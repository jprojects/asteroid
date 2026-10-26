<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Carga, valida y guarda un registro a partir de la definición del formulario.
 */
final class FormHandler
{
    public function __construct(
        private readonly Module $module,
        private readonly Database $db,
    ) {
    }

    /** @return array<string,mixed>|null valores por nombre de campo */
    public function load(string $id): ?array
    {
        $cols = [];
        $computed = array_filter($this->module->fields(), static fn ($f) => $f->computeFunction() !== null);
        if ($computed) {
            $cols[] = Database::ident($this->module->table()) . '.*'; // los cálculos PHP reciben la fila completa
        }
        foreach ($this->module->fields() as $f) {
            if (($expr = $f->sqlExpr()) !== null) {
                $cols[] = $expr . ' AS ' . Database::ident($f->name);
            }
        }
        // Solo si el usuario puede ver el registro ("where" y "scope" del módulo)
        $scope = $this->module->rowCondition();
        $row = $this->db->fetch(sprintf(
            'SELECT %s FROM %s WHERE %s = ?%s',
            implode(', ', $cols),
            Database::ident($this->module->table()),
            Database::ident($this->module->primaryKey()),
            $scope !== null ? ' AND ' . $scope[0] : ''
        ), [$id, ...($scope[1] ?? [])]);
        if ($row === null) {
            return null;
        }
        foreach ($computed as $f) {
            $row[$f->name] = $this->module->compute($f, $row);
        }
        foreach ($this->module->fields() as $f) {
            if (array_key_exists($f->name, $row)) {
                $row[$f->name] = $f->fromDb($row[$f->name]);
            }
        }
        return $row;
    }

    public function defaults(): array
    {
        $values = [];
        foreach ($this->module->fields() as $f) {
            $values[$f->name] = $f->defaultValue();
        }
        return $values;
    }

    /**
     * @return array{ok:bool,id:?string,values:array,errors:array<string,string>}
     */
    public function process(array $input, ?string $id, User $user): array
    {
        $isNew = $id === null;
        $existing = $isNew ? null : $this->load($id);
        if (!$isNew && $existing === null) {
            throw new HttpException(404); // no existe o el usuario no puede verlo
        }
        $values = $existing ?? $this->defaults();
        $errors = [];
        $data = [];

        $fields = $this->module->formFields();
        foreach ($fields as $f) {
            if (!$f->isReadonly()) {
                $values[$f->name] = $f->fromRequest($input);
            }
        }
        // Campos ocultos por showon: ni se validan ni se guardan (conservan su valor)
        $visible = ShowOn::visibility($fields, $values);

        foreach ($fields as $f) {
            if ($f->isReadonly() || !$visible[$f->name]) {
                continue;
            }
            $value = $values[$f->name];

            $fieldErrors = $f->validate($value, $isNew);
            if (!$fieldErrors && $f->isUnique() && $value !== '' && $value !== null && !$this->isUniqueValue($f->column(), $f->toDb($value), $id)) {
                $fieldErrors[] = $this->module->t('validation.unique');
            }
            if ($fieldErrors) {
                $errors[$f->name] = $fieldErrors[0];
                continue;
            }
            if ($f->shouldSave($value, $isNew)) {
                $data[$f->column()] = $f->toDb($value);
            }
        }

        if ($errors) {
            return ['ok' => false, 'id' => $id, 'values' => $values, 'errors' => $errors];
        }

        $hooks = $this->module->hooks($this->db);
        $hooks?->setFormValues(array_intersect_key($values, array_filter($visible)));
        if ($hooks && ($err = $hooks->beforeSave($data, $id, $existing, $user)) !== null) {
            return ['ok' => false, 'id' => $id, 'values' => $values, 'errors' => ['_form' => $this->module->t($err)]];
        }

        // El "scope" fija columnas (p. ej. el cliente del usuario): nadie guarda fuera de lo que ve
        $data = array_merge($data, $this->module->scopeValues($user));

        $table = $this->module->table();
        $pk = $this->module->primaryKey();
        if ($isNew) {
            $insertId = $this->db->insert($table, $data);
            $id = isset($data[$pk]) ? (string) $data[$pk] : $insertId;
        } else {
            $this->db->update($table, $data, $pk, $id);
        }

        $hooks?->afterSave($id, $data, $isNew, $user);
        return ['ok' => true, 'id' => $id, 'values' => $values, 'errors' => []];
    }

    private function isUniqueValue(string $column, mixed $value, ?string $id): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = ?',
            Database::ident($this->module->table()),
            Database::ident($column)
        );
        $params = [$value];
        if ($id !== null) {
            $sql .= ' AND ' . Database::ident($this->module->primaryKey()) . ' <> ?';
            $params[] = $id;
        }
        return (int) $this->db->fetchValue($sql, $params) === 0;
    }
}
