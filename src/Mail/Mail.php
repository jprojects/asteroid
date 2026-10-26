<?php
declare(strict_types=1);

namespace Quasar\Mail;

/**
 * Fachada para enviar correo desde cualquier punto del código (hooks, campos, scripts):
 *
 *   use Quasar\Mail\Mail;
 *
 *   Mail::send(Mail::message()->to('ana@ejemplo.es')->subject('Hola')->text('…'));
 *   Mail::send(Mail::compose('emails/aviso', ['nombre' => 'Ana'])->to('ana@ejemplo.es')->subject('Aviso'));
 *
 * La App la conecta con su Mailer al arrancar.
 */
final class Mail
{
    /** @var \Closure():Mailer|null */
    private static ?\Closure $factory = null;
    private static ?Mailer $mailer = null;

    /** @param \Closure():Mailer $factory */
    public static function setFactory(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$mailer = null;
    }

    public static function mailer(): Mailer
    {
        if (self::$mailer === null) {
            if (self::$factory === null) {
                throw new MailException('El correo no está inicializado (crea antes la App)');
            }
            self::$mailer = (self::$factory)();
        }
        return self::$mailer;
    }

    public static function message(): Message
    {
        return self::mailer()->message();
    }

    public static function compose(string $template, array $vars = []): Message
    {
        return self::mailer()->compose($template, $vars);
    }

    public static function send(Message $message): void
    {
        self::mailer()->send($message);
    }

    public static function trySend(Message $message): bool
    {
        return self::mailer()->trySend($message);
    }
}
