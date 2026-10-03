<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Paginator;
use Core\Response;

/**
 * Seasons: the production calendar the whole platform is measured against.
 *
 * Every planting, activity and harvest belongs to a season, and one season at a
 * time is treated as open so that a single "current season" drives forecasts,
 * dashboards and reminders. Opening a season therefore closes whatever was open
 * before it, in the same transaction, so the system can never be left reporting
 * against two seasons at once. A season that holds records is never deleted —
 * the history it anchors is the evidence behind cooperative reporting.
 */
final class SeasonController extends Controller
{
    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(s.season_code LIKE :s OR s.name LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        if (($params['filters']['status'] ?? '') !== '') {
            $where[]            = 's.status = :status';
            $values['status']   = $params['filters']['status'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM seasons s' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['season_code', 'name', 'start_date', 'end_date', 'status'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'start_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT s.id, s.season_code, s.name, s.start_date, s.end_date, s.status, s.created_at,
                    (SELECT COUNT(*) FROM plantings p WHERE p.season_id = s.id) AS planting_count,
                    (SELECT COUNT(*) FROM crop_activities a WHERE a.season_id = s.id AND a.status <> \'cancelled\') AS activity_count,
                    (SELECT COUNT(*) FROM harvest_records h WHERE h.season_id = s.id AND h.status <> \'cancelled\') AS harvest_count,
                    (SELECT COALESCE(SUM(p.acreage_planted), 0) FROM plantings p WHERE p.season_id = s.id) AS planted_acreage
             FROM seasons s'
            . $whereSql
            . ' ORDER BY s.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('seasons/index', [
            'pageTitle' => 'Seasons',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'upcoming','open','closed'", 'All statuses')],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('seasons/form', [
            'pageTitle' => 'Add season',
            'record'    => null,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('end_date', 'start_date', 'End date', 'Start date');
        $validator->unique('season_code', 'seasons', 'season_code', null, 'Season code');

        if ($validator->fails()) {
            return $this->backWithErrors(url('seasons/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, []);

        $result = $this->db->transaction(function () use ($values): array {
            $id = $this->db->insert('seasons', $values);

            return ['id' => $id, 'closed' => ($values['status'] ?? '') === 'open' ? $this->closeOtherSeasons($id) : []];
        });

        if ($result['closed'] !== []) {
            Audit::log(
                'close',
                'seasons',
                'season',
                null,
                implode(', ', $result['closed']),
                ['status' => 'open'],
                ['status' => 'closed']
            );
        }

        Audit::log('create', 'seasons', 'season', $result['id'], (string) $values['season_code'], null, $values);

        $this->reportSeasonOpen($result['closed']);

        Flash::success('Season ' . $values['season_code'] . ' has been added.');

        return $this->redirect(url('seasons'));
    }

    public function edit(string $id): Response
    {
        $season = $this->findOrFail($id);

        return $this->render('seasons/form', [
            'pageTitle' => 'Edit ' . $season['season_code'],
            'record'    => $season,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $season   = $this->findOrFail($id);
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('end_date', 'start_date', 'End date', 'Start date');
        $validator->unique('season_code', 'seasons', 'season_code', (int) $season['id'], 'Season code');

        if ($validator->fails()) {
            return $this->backWithErrors(url('seasons/' . $id . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, []);

        $closed = $this->db->transaction(function () use ($values, $season): array {
            $this->db->update('seasons', $values, 'id = :id', ['id' => $season['id']]);

            if (($values['status'] ?? '') !== 'open') {
                return [];
            }

            return $this->closeOtherSeasons((int) $season['id']);
        });

        Audit::log('update', 'seasons', 'season', (int) $season['id'], (string) $season['season_code'], $season, $values);

        if ($closed !== []) {
            Audit::log(
                'close',
                'seasons',
                'season',
                null,
                implode(', ', $closed),
                ['status' => 'open'],
                ['status' => 'closed']
            );
        }

        $this->reportSeasonOpen($closed);

        Flash::success('Season ' . $season['season_code'] . ' has been updated.');

        return $this->redirect(url('seasons'));
    }

    /**
     * Seasons are never deleted once they hold records: the plantings, activity
     * records and harvests filed against them are the evidence behind every
     * cooperative report. Closing the season is the reversible alternative.
     */
    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $season = $this->findOrFail($id);

        $plantings = (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE season_id = :id', ['id' => $season['id']]);
        $activities = (int) $this->db->scalar('SELECT COUNT(*) FROM crop_activities WHERE season_id = :id', ['id' => $season['id']]);
        $harvests   = (int) $this->db->scalar('SELECT COUNT(*) FROM harvest_records WHERE season_id = :id', ['id' => $season['id']]);

        $dependents = $plantings + $activities + $harvests;

        if ($dependents > 0) {
            $breakdown = [];
            if ($plantings > 0) {
                $breakdown[] = $plantings . ' planting(s)';
            }
            if ($activities > 0) {
                $breakdown[] = $activities . ' activity record(s)';
            }
            if ($harvests > 0) {
                $breakdown[] = $harvests . ' harvest record(s)';
            }

            Flash::error(
                'Season ' . $season['season_code'] . ' holds ' . implode(', ', $breakdown) . ' and cannot be '
                . 'deleted. Set it to closed instead: the records stay readable and the season stops accepting '
                . 'new work.'
            );

            return $this->redirect(url('seasons/' . $season['id'] . '/edit'));
        }

        $this->db->delete('seasons', 'id = :id', ['id' => $season['id']]);

        Audit::log('delete', 'seasons', 'season', (int) $season['id'], (string) $season['season_code'], $season);

        Flash::success('Season ' . $season['season_code'] . ' has been deleted.');

        return $this->redirect(url('seasons'));
    }

    /**
     * Closes every other season that is still open, so only one season is ever
     * reported as current. Runs inside the caller's transaction.
     *
     * @return array<int,string> Season codes that were closed
     */
    private function closeOtherSeasons(int $seasonId): array
    {
        $open = $this->db->select(
            "SELECT season_code FROM seasons WHERE status = 'open' AND id <> :id FOR UPDATE",
            ['id' => $seasonId]
        );

        if ($open === []) {
            return [];
        }

        $this->db->update('seasons', ['status' => 'closed'], "status = 'open' AND id <> :id", ['id' => $seasonId]);

        return array_map('strval', array_column($open, 'season_code'));
    }

    /**
     * @param array<int,string> $closed
     */
    private function reportSeasonOpen(array $closed): void
    {
        if ($closed === []) {
            return;
        }

        Flash::info(
            'Only one season can be open at a time, so ' . implode(', ', $closed) . ' was closed automatically.'
        );
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Season',
                'fields' => [
                    ['name' => 'season_code', 'label' => 'Season code', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'maxlength' => 20, 'placeholder' => 'e.g. 2026A',
                     'help' => 'Short code used on reports and printed records, e.g. 2026A for the first season of 2026.'],
                    ['name' => 'name', 'label' => 'Season name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'e.g. Long rains 2026'],
                    ['name' => 'start_date', 'label' => 'Start date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6],
                    ['name' => 'end_date', 'label' => 'End date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'upcoming','open','closed'", ''),
                     'help' => 'Setting a season to open closes whichever season is currently open.'],
                ],
            ],
        ];
    }

    private function findOrFail(string $id): array
    {
        $season = $this->db->selectOne('SELECT * FROM seasons WHERE id = :id', ['id' => $id]);

        if ($season === null) {
            throw new HttpException(404, 'No season found with the ID ' . $id . '.');
        }

        return $season;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
