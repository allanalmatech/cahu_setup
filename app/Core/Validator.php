<?php
declare(strict_types=1);

namespace Core;

/**
 * Server side validation. Every form is validated here as well as in the
 * browser; client side checks are a convenience only.
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label = ''): self
    {
        $label = $label ?: ucfirst(str_replace('_', ' ', $field));
        $value = $this->value($field);

        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            $this->errors[$field] = $label . ' is required.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function email(string $field, string $label = 'Email address', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = $label . ' is required.';
            }

            return $this;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $label . ' must be a valid email address.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function phone(string $field, string $label = 'Phone number', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = $label . ' is required.';
            }

            return $this;
        }

        $digits = preg_replace('/[^0-9+]/', '', (string) $value) ?? '';

        if (strlen($digits) < 7 || strlen($digits) > 20) {
            $this->errors[$field] = $label . ' must be a valid phone number.';

            return $this;
        }

        return $this->mark($field, $digits);
    }

    public function min(string $field, int $length, string $label = ''): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && mb_strlen((string) $value) < $length) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field)))
                . " must be at least {$length} characters.";

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function max(string $field, int $length, string $label = ''): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && mb_strlen((string) $value) > $length) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field)))
                . " may not be longer than {$length} characters.";

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function numeric(string $field, string $label = '', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is required.';
            }

            return $this;
        }

        if (!is_numeric($value)) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' must be a number.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function minValue(string $field, float $min, string $label = ''): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && is_numeric($value) && (float) $value < $min) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field)))
                . " may not be less than {$min}.";

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function date(string $field, string $label = '', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is required.';
            }

            return $this;
        }

        $date = \DateTime::createFromFormat('Y-m-d', (string) $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' must be a valid date.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function in(string $field, array $allowed, string $label = '', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is required.';
            }

            return $this;
        }

        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' has an invalid value.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    public function exists(string $field, string $table, string $column = 'id', string $label = '', bool $required = true): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is required.';
            }

            return $this;
        }

        $table  = Database::safeIdentifier($table);
        $column = Database::safeIdentifier($column);

        $exists = Database::instance()->scalar(
            "SELECT 1 FROM `{$table}` WHERE `{$column}` = :value LIMIT 1",
            ['value' => $value]
        );

        if ($exists === null) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is not a valid selection.';

            return $this;
        }

        return $this->mark($field, $value);
    }

    /**
     * Ensures a date range is coherent, e.g. season start before end.
     */
    public function dateAfter(string $field, string $otherField, string $label = '', string $otherLabel = ''): self
    {
        $value = $this->value($field);
        $other = $this->value($otherField);

        if ($value !== null && $other !== null && $value !== '' && $other !== '') {
            if (strtotime((string) $value) < strtotime((string) $other)) {
                $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field)))
                    . ' may not be earlier than ' . ($otherLabel ?: str_replace('_', ' ', $otherField)) . '.';
            }
        }

        return $this;
    }

    public function unique(string $field, string $table, string $column, ?int $ignoreId = null, string $label = ''): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            return $this;
        }

        $table  = Database::safeIdentifier($table);
        $column = Database::safeIdentifier($column);

        $sql    = "SELECT 1 FROM `{$table}` WHERE `{$column}` = :value";
        $params = ['value' => $value];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        if (Database::instance()->scalar($sql, $params) !== null) {
            $this->errors[$field] = ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is already in use.';
        }

        return $this->mark($field, $value);
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;

        return $this;
    }

    private function value(string $field): mixed
    {
        $value = $this->data[$field] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    private function mark(string $field, mixed $value): self
    {
        $this->validated[$field] = $value;

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : (string) reset($this->errors);
    }

    /**
     * Validated (trimmed) values for the fields that passed, so controllers
     * never touch raw input again.
     */
    public function validated(): array
    {
        return $this->validated;
    }
}
