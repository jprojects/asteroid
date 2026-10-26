<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Fields\Field;

/**
 * Campos condicionales: "showon" en el JSON de un campo.
 *
 *   "showon": "vip:1"                         vip vale 1
 *   "showon": "status:active,inactive"        status es uno de esos valores
 *   "showon": "status!:lead"                  status NO es lead
 *   "showon": "email!:"                       email no está vacío   ("email:" = está vacío)
 *   "showon": "vip:1[AND]status:active"       todas las condiciones
 *   "showon": "vip:1[OR]credit_limit!:"       alguna de ellas ([AND] tiene prioridad sobre [OR])
 *
 * Un checkbox vale "1" o "0". Un campo oculto cuenta como vacío para los campos
 * que dependen de él (las cadenas A → B → C se resuelven solas).
 * La misma evaluación se hace en el navegador (app.js) con la estructura de parse().
 */
final class ShowOn
{
    /**
     * @return list<list<array{field:string,not:bool,values:list<string>}>> grupos OR de condiciones AND
     */
    public static function parse(string $expr): array
    {
        $groups = [];
        foreach (preg_split('/\[OR\]/i', $expr) ?: [] as $group) {
            $conditions = [];
            foreach (preg_split('/\[AND\]/i', $group) ?: [] as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }
                if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(!?):(.*)$/s', $cond, $m)) {
                    throw new \InvalidArgumentException("Condición showon no válida: '$cond' (formato: campo:valor o campo!:valor)");
                }
                $conditions[] = [
                    'field' => $m[1],
                    'not' => $m[2] === '!',
                    'values' => array_values(array_map('trim', explode(',', $m[3]))),
                ];
            }
            if ($conditions) {
                $groups[] = $conditions;
            }
        }
        if (!$groups) {
            throw new \InvalidArgumentException("Condición showon vacía: '$expr'");
        }
        return $groups;
    }

    /** @return list<string> campos de los que depende la condición */
    public static function fields(array $parsed): array
    {
        $names = [];
        foreach ($parsed as $group) {
            foreach ($group as $cond) {
                $names[$cond['field']] = true;
            }
        }
        return array_keys($names);
    }

    /** @param \Closure(string):mixed $valueOf valor actual de un campo */
    public static function evaluate(array $parsed, \Closure $valueOf): bool
    {
        foreach ($parsed as $group) {
            $all = true;
            foreach ($group as $cond) {
                if (!self::matches($cond, $valueOf($cond['field']))) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    /**
     * Visibilidad de un conjunto de campos (un formulario o una fila de repeater).
     * Se repite hasta que no cambia nada, para resolver dependencias encadenadas.
     *
     * @param array<string,Field>|list<Field> $fields
     * @param array<string,mixed> $values valores por nombre de campo
     * @return array<string,bool> nombre => visible
     */
    public static function visibility(array $fields, array $values): array
    {
        $visible = [];
        foreach ($fields as $f) {
            $visible[$f->name] = true;
        }
        for ($pass = 0; $pass <= count($visible); $pass++) {
            $changed = false;
            foreach ($fields as $f) {
                $parsed = $f->showOn();
                if ($parsed === null) {
                    continue;
                }
                $show = self::evaluate($parsed, static fn (string $name) => ($visible[$name] ?? true) ? ($values[$name] ?? null) : '');
                if ($show !== $visible[$f->name]) {
                    $visible[$f->name] = $show;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }
        return $visible;
    }

    private static function matches(array $cond, mixed $value): bool
    {
        if (is_array($value)) {
            $values = $value === [] ? [''] : array_map(static fn ($v) => self::str($v), array_values($value));
        } else {
            $values = [self::str($value)];
        }
        $hit = (bool) array_intersect($values, $cond['values']);
        return $cond['not'] ? !$hit : $hit;
    }

    private static function str(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? '1' : '0',
            is_scalar($v) => trim((string) $v),
            default => '',
        };
    }
}
