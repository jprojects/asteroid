<?php
declare(strict_types=1);

namespace Quasar\Core;

use DateTimeImmutable;

/**
 * Eventos de fuera para un calendario (CalendarPage, opción "extra" del JSON de la página): por ejemplo los de
 * Google Calendar. Son de solo lectura: no se arrastran ni se estiran, y su enlace se abre en otra pestaña.
 *
 *   "calendar": { "source": "issues", "date": "due_on", …, "extra": "Quasar\\Hooks\\AgendaGoogle" }
 *
 * La clase se crea sin argumentos. Un error suyo no rompe la página: se anota en el log y el calendario se muestra
 * sin esos eventos.
 *
 * Opcional: un método `notice(App $app, User $user): ?array` que devuelve
 * `['text' => '…', 'links' => [['label' => '…', 'url' => '…', 'active' => bool], …]]`; se muestra encima del
 * calendario (p. ej. «Connecta el teu calendari» o un selector «El meu · Tot l'equip»). Se llama antes que legend()
 * y events().
 */
interface CalendarSource
{
    /**
     * Eventos que empiezan en [$from, $to).
     * @return list<array{start:string,end?:?string,allDay?:bool,title:string,url?:?string,tone?:?string,label?:?string}>
     *   start/end: "Y-m-d H:i" (o "Y-m-d" si es de todo el día; el fin de un evento de días, exclusivo, como en Google);
     *   tone: "#rrggbb" o un tono de la paleta (green, blue…); label: texto de la leyenda (p. ej. de quién es)
     */
    public function events(App $app, User $user, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return list<array{label:string,tone:string}> entradas de la leyenda */
    public function legend(App $app, User $user): array;
}
