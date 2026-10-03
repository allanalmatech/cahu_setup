<?php
declare(strict_types=1);

namespace App;

use Core\Auth;
use Core\Database;

/**
 * Builds the main navigation. Items the current user has no permission for are
 * omitted entirely, so the menu never offers a link that would be refused by
 * the server.
 */
final class Navigation
{
    private const SECTIONS = [
        [
            'label' => 'Overview',
            'items' => [
                ['label' => 'Dashboard',    'url' => '/dashboard',       'match' => '/dashboard', 'icon' => 'M3 3h7v7H3zM14 3h7v4h-7zM14 11h7v10h-7zM3 14h7v7H3z', 'permission' => null],
                ['label' => 'My Field Work', 'url' => '/portal',         'match' => '/portal',    'icon' => 'M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z', 'permission' => null],
                ['label' => 'Command Centre', 'url' => '/command-centre', 'match' => '/command-centre', 'icon' => 'M4 20V10M10 20V4M16 20v-7M22 20H2', 'permission' => 'reports.view'],
            ],
        ],
        [
            'label' => 'Farmers',
            'items' => [
                ['label' => 'All Farmers',  'url' => '/farmers',  'match' => '/farmers',  'icon' => 'M17 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87', 'permission' => 'farmers.view',
                 'children' => [
                     ['label' => 'Register Farmer',   'url' => '/farmers/create', 'match' => '/farmers/create'],
                     ['label' => 'Farmer Performance', 'url' => '/reports/farmer-performance', 'match' => '/reports/farmer-performance'],
                 ]],
                ['label' => 'Groups',      'url' => '/groups',  'match' => '/groups',  'icon' => 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75', 'permission' => 'groups.view'],
                ['label' => 'Field Officers', 'url' => '/officers', 'match' => '/officers', 'icon' => 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z', 'permission' => 'officers.view'],
            ],
        ],
        [
            'label' => 'Farms',
            'items' => [
                ['label' => 'Gardens',      'url' => '/gardens', 'match' => '/gardens', 'icon' => 'M1 6l7-3 8 3 7-3v15l-7 3-8-3-7 3zM8 3v15M16 6v15', 'permission' => 'gardens.view'],
                ['label' => 'Farm Map',     'url' => '/map',     'match' => '/map',     'icon' => 'M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z', 'permission' => 'map.view', 'iconTag' => 'M12 11a3 3 0 100-6 3 3 0 000 6z'],
            ],
        ],
        [
            'label' => 'Production',
            'items' => [
                ['label' => 'Crops',   'url' => '/crops',     'match' => '/crops',     'icon' => 'M12 22c4.4 0 8-3.6 8-8 0-5-8-12-8-12S4 9 4 14c0 4.4 3.6 8 8 8z', 'permission' => 'crops.view',
                 'children' => [
                     ['label' => 'Varieties', 'url' => '/varieties', 'match' => '/varieties'],
                     ['label' => 'Seasons',   'url' => '/seasons',   'match' => '/seasons'],
                 ]],
                ['label' => 'Plantings',  'url' => '/plantings',  'match' => '/plantings',  'icon' => 'M12 19V5M5 12l7-7 7 7', 'permission' => 'plantings.view'],
                ['label' => 'Activities', 'url' => '/activities', 'match' => '/activities', 'icon' => 'M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11', 'permission' => 'activities.view',
                 'children' => [
                     ['label' => 'Calendar',  'url' => '/activities/calendar', 'match' => '/activities/calendar'],
                     ['label' => 'Overdue',   'url' => '/activities?status=overdue', 'match' => '/activities?status=overdue'],
                     ['label' => 'Completed', 'url' => '/activities?status=completed', 'match' => '/activities?status=completed'],
                 ]],
                ['label' => 'Harvest',  'url' => '/harvest', 'match' => '/harvest', 'icon' => 'M6 2v6l6 6 6-6V2H6zM12 14v8', 'permission' => 'harvest.view',
                 'children' => [
                     ['label' => 'Forecast', 'url' => '/forecast', 'match' => '/forecast'],
                     ['label' => 'Batches',  'url' => '/batches',  'match' => '/batches'],
                 ]],
            ],
        ],
        [
            'label' => 'Field Ops',
            'items' => [
                ['label' => 'Inspections', 'url' => '/inspections', 'match' => '/inspections', 'icon' => 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'permission' => 'inspections.view'],
                ['label' => 'Visits',      'url' => '/visits',      'match' => '/visits',      'icon' => 'M3 20h18M5 20V9l7-5 7 5v11M9 20v-6h6v6', 'permission' => 'visits.view'],
                ['label' => 'Spraying',    'url' => '/spraying',    'match' => '/spraying',    'icon' => 'M9 3h6l1 5H8zM12 8v13M8 13h8', 'permission' => 'spraying.view'],
                ['label' => 'Problems',    'url' => '/problems',    'match' => '/problems',    'icon' => 'M12 9v4m0 4h.01M10.3 3.9L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z', 'permission' => 'problems.view'],
            ],
        ],
        [
            'label' => 'Inputs',
            'items' => [
                ['label' => 'Inventory', 'url' => '/inputs',       'match' => '/inputs',       'icon' => 'M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z', 'permission' => 'inputs.view',
                 'children' => [
                     ['label' => 'Issues', 'url' => '/input-issues', 'match' => '/input-issues'],
                     ['label' => 'Usage',  'url' => '/input-usage',  'match' => '/input-usage'],
                 ]],
            ],
        ],
        [
            'label' => 'Markets',
            'items' => [
                ['label' => 'Buyers',    'url' => '/buyers',  'match' => '/buyers',  'icon' => 'M3 9l1.5-5h15L21 9M3 9h18M3 9v11h18V9M9 20v-6h6v6', 'permission' => 'markets.view'],
                ['label' => 'Demand',    'url' => '/demand',  'match' => '/demand',  'icon' => 'M3 3v18h18M7 15l3-4 3 3 5-7', 'permission' => 'markets.view'],
                ['label' => 'Sales',     'url' => '/sales',   'match' => '/sales',   'icon' => 'M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11', 'permission' => 'sales.manage'],
                ['label' => 'Deliveries', 'url' => '/deliveries', 'match' => '/deliveries', 'icon' => 'M1 3h15v13H1zM16 8h4l3 3v5h-7V8z', 'permission' => 'sales.manage'],
                ['label' => 'Exports',   'url' => '/exports', 'match' => '/exports', 'icon' => 'M12 2v20M2 12h20M12 2l-3 3M12 2l3 3M12 22l-3-3M12 22l3-3', 'permission' => 'exports.manage'],
            ],
        ],
        [
            'label' => 'Knowledge',
            'items' => [
                ['label' => 'Knowledge Centre', 'url' => '/knowledge', 'match' => '/knowledge', 'icon' => 'M4 19.5A2.5 2.5 0 016.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z', 'permission' => 'knowledge.view'],
                ['label' => 'Reports',          'url' => '/reports',   'match' => '/reports',   'icon' => 'M9 17v-2m4 2v-4m4 4v-6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z', 'permission' => 'reports.view'],
            ],
        ],
        [
            'label' => 'Administration',
            'items' => [
                ['label' => 'Users & Roles', 'url' => '/admin/users', 'match' => '/admin/users', 'icon' => 'M12 2a5 5 0 100 10 5 5 0 000-10zM4 21a8 8 0 0116 0', 'permission' => 'admin.users'],
                ['label' => 'Reference Data', 'url' => '/admin/reference', 'match' => '/admin/reference', 'icon' => 'M4 6h16M4 12h16M4 18h10', 'permission' => 'admin.settings'],
                ['label' => 'Audit Log',     'url' => '/admin/audit',  'match' => '/admin/audit',  'icon' => 'M9 12h6m-6 4h6M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z', 'permission' => 'admin.audit'],
                ['label' => 'Settings',      'url' => '/admin',        'match' => '/admin',        'icon' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-2.9 1.2V21a2 2 0 11-4 0v-.1A1.7 1.7 0 006.6 19a1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.7 1.7 0 003 15H3a2 2 0 110-4h.1A1.7 1.7 0 004.6 9a1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1A1.7 1.7 0 009 4.6V4a2 2 0 114 0v.1A1.7 1.7 0 0015 6.6a1.7 1.7 0 001.8.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z', 'permission' => 'admin.settings'],
            ],
        ],
    ];

    private const SVG = 'M3 3h7v7H3zM14 3h7v4h-7zM14 11h7v10h-7zM3 14h7v7H3z';

    /**
     * @return array<int,array{label:string,items:array<int,array<string,mixed>>}>
     */
    public static function tree(Auth $auth): array
    {
        $sections = [];

        foreach (self::SECTIONS as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                $permission = $item['permission'] ?? null;

                if ($permission !== null && !$auth->can($permission)) {
                    continue;
                }

                $item['icon']   = self::icon((string) $item['icon']);
                $item['url']    = url(ltrim((string) $item['url'], '/'));
                $item['match']  = (string) $item['match'];

                if (!empty($item['children'])) {
                    foreach ($item['children'] as &$child) {
                        $child['url']   = url(ltrim((string) $child['url'], '/'));
                        $child['match'] = (string) $child['match'];
                    }
                    unset($child);
                }

                $items[] = $item;
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Wraps an SVG path definition in a 16px stroke icon.
     */
    private static function icon(string $path): string
    {
        return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" '
            . 'stroke-linecap="round" stroke-linejoin="round"><path d="' . $path . '"/></svg>';
    }
}
