<?php
declare(strict_types=1);

namespace Core;

/**
 * Form field builder and binder.
 *
 * A field definition is a plain array, which keeps every form in the system
 * described in one consistent vocabulary and lets the same partial render a
 * text box, a select, a date, a GPS capture pair or a hidden value. Definitions
 * are also used for validation, so a rule can never be added to the UI without
 * the matching server-side check.
 *
 * Definition keys:
 *   name, label, type, value, required, help, options, min, max, step,
 *   placeholder, rows, readonly, accept, class, colspan, half
 */
final class Form
{
    public const TEXT     = 'text';
    public const NUMBER   = 'number';
    public const DATE     = 'date';
    public const DATETIME = 'datetime-local';
    public const SELECT   = 'select';
    public const MULTI    = 'multiselect';
    public const TEXTAREA = 'textarea';
    public const CHECKBOX = 'checkbox';
    public const EMAIL    = 'email';
    public const TEL      = 'tel';
    public const PASSWORD = 'password';
    public const GPS      = 'gps';
    public const HIDDEN   = 'hidden';
    public const STATIC   = 'static';
    public const FILE     = 'file';
    public const MONTH    = 'month';

    /**
     * Builds a select's options from a database query result.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{value:string,label:string}>
     */
    public static function options(array $rows, string $valueKey = 'id', string $labelKey = 'name', string $placeholder = 'Select...'): array
    {
        $options = [];

        if ($placeholder !== '') {
            $options[] = ['value' => '', 'label' => $placeholder];
        }

        foreach ($rows as $row) {
            $label = (string) $row[$labelKey];

            if (isset($row['district_name']) && $row['district_name'] !== '' && $labelKey !== 'district_name') {
                $label .= ' (' . $row['district_name'] . ')';
            }

            $options[] = ['value' => (string) $row[$valueKey], 'label' => $label];
        }

        return $options;
    }

    /**
     * Builds options from an ENUM column definition without a query. The
     * definition is written in the same form as MySQL declares it, for example
     * "'active','inactive','pending'".
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function enumOptions(string $enumList, string $placeholder = 'Select...'): array
    {
        $options = $placeholder === '' ? [] : [['value' => '', 'label' => $placeholder]];

        foreach (explode(',', trim($enumList, "()' ")) as $value) {
            $value = trim($value, "()' \t\n\r");

            if ($value === '') {
                continue;
            }

            $options[] = ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))];
        }

        return $options;
    }

    /**
     * Pulls a single value out of a record, falling back to old input and then
     * to the definition default.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed>|null $record
     */
    public static function value(array $definition, ?array $record = null, mixed $default = null): mixed
    {
        $name = (string) $definition['name'];

        $old = \Core\Flash::input();

        if (array_key_exists($name, $old)) {
            return $old[$name];
        }

        if ($record !== null && array_key_exists($name, $record)) {
            return $record[$name];
        }

        return $default ?? ($definition['default'] ?? '');
    }

    /**
     * Builds a Validator from the field definitions, applying the rules each
     * field declares. This guarantees the server enforces exactly what the form
     * displays.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed> $data
     */
    public static function validate(array $fields, array $data): Validator
    {
        $validator = Validator::make($data);

        foreach ($fields as $field) {
            $name     = (string) $field['name'];
            $type     = (string) ($field['type'] ?? self::TEXT);
            $label    = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name)));
            $required = (bool) ($field['required'] ?? false);

            if ($type === self::HIDDEN || $type === self::STATIC || $type === self::CHECKBOX) {
                continue;
            }

            $value = $data[$name] ?? null;

            if ($required && ($value === null || $value === '')) {
                $validator->addError($name, $label . ' is required.');
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            match ($type) {
                self::EMAIL    => $validator->email($name, $label, $required),
                self::TEL      => $validator->phone($name, $label, $required),
                self::NUMBER   => self::applyNumberRules($validator, $field, $name, $label, $value),
                self::DATE,
                self::DATETIME,
                self::MONTH    => $validator->date($name, $label, $required),
                self::SELECT   => isset($field['options'])
                    ? $validator->in($name, array_column($field['options'], 'value'), $label, $required)
                    : $validator->exists($name, (string) $field['table'], (string) ($field['column'] ?? 'id'), $label, $required),
                default         => self::applyTextRules($validator, $field, $name, $label, $value),
            };
        }

        return $validator;
    }

    private static function applyNumberRules(Validator $validator, array $field, string $name, string $label, mixed $value): void
    {
        if (!is_numeric($value)) {
            $validator->addError($name, $label . ' must be a number.');
            return;
        }

        if (isset($field['min'])) {
            $validator->minValue($name, (float) $field['min'], $label);
        }

        if (isset($field['max']) && (float) $value > (float) $field['max']) {
            $validator->addError($name, $label . ' may not be greater than ' . $field['max'] . '.');
        }
    }

    private static function applyTextRules(Validator $validator, array $field, string $name, string $label, mixed $value): void
    {
        if (isset($field['minlength'])) {
            $validator->min($name, (int) $field['minlength'], $label);
        }

        if (isset($field['maxlength'])) {
            $validator->max($name, (int) $field['maxlength'], $label);
        }

        if (!empty($field['unique']) && is_array($field['unique'])) {
            $validator->unique(
                $name,
                (string) $field['unique']['table'],
                (string) ($field['unique']['column'] ?? $name),
                $field['unique']['ignore'] ?? null,
                $label
            );
        }
    }

    /**
     * Extracts only the declared field names from submitted input, trimming
     * strings and normalising empty strings to null for nullable columns.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed> $data
     * @param array<int,string> $nullable
     * @return array<string,mixed>
     */
    public static function extract(array $fields, array $data, array $nullable = []): array
    {
        $values = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $type = (string) ($field['type'] ?? self::TEXT);

            if ($type === self::STATIC || $type === self::FILE) {
                continue;
            }

            // A GPS field is two controls, so it posts as `<name>_lat` and
            // `<name>_lng` and the name itself never appears in the body. It is
            // expanded into the two real columns here rather than being looked up
            // under its own name, which would always miss and silently store a
            // location-less record.
            if ($type === self::GPS) {
                $lat = $data[$name . '_lat'] ?? null;
                $lng = $data[$name . '_lng'] ?? null;

                $valid = is_numeric($lat) && is_numeric($lng)
                    && (float) $lat >= -90.0 && (float) $lat <= 90.0
                    && (float) $lng >= -180.0 && (float) $lng <= 180.0;

                $values['latitude']  = $valid ? (float) $lat : null;
                $values['longitude'] = $valid ? (float) $lng : null;

                continue;
            }

            // A readonly field is shown so the user knows the value, never
            // accepted as input. Record identifiers and codes rely on this:
            // without it a crafted POST could rewrite an issued identifier.
            if (!empty($field['readonly'])) {
                continue;
            }

            if ($type === self::CHECKBOX) {
                $values[$name] = !empty($data[$name]) ? 1 : 0;
                continue;
            }

            if ($type === self::MULTI) {
                $values[$name] = is_array($data[$name] ?? null) ? array_values($data[$name]) : [];
                continue;
            }

            if (!array_key_exists($name, $data)) {
                if (in_array($name, $nullable, true)) {
                    $values[$name] = null;
                }
                continue;
            }

            $value = $data[$name];

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' && in_array($name, $nullable, true)) {
                $values[$name] = null;
                continue;
            }

            if ($value === '' && !in_array($name, $nullable, true) && ($field['required'] ?? false)) {
                continue;
            }

            $values[$name] = $value;
        }

        return $values;
    }
}
