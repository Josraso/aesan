<?php
// includes/mailer.php – Envío de emails de notificación (PHP mail nativo, sin librerías)

class Mailer {

    private static function config(): array {
        return [
            'from_email' => defined('MAIL_FROM')    ? MAIL_FROM    : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'from_name'  => defined('MAIL_FROM_NAME')? MAIL_FROM_NAME : 'AESAN Checker',
            'admin_email'=> defined('MAIL_ADMIN')   ? MAIL_ADMIN   : '',
        ];
    }

    /**
     * Enviar email básico con PHP mail()
     */
    public static function enviar(string $to, string $asunto, string $cuerpoHtml): bool {
        $cfg  = self::config();
        $from = "{$cfg['from_name']} <{$cfg['from_email']}>";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: {$from}\r\n";
        $headers .= "X-Mailer: AESAN-Checker/1.0\r\n";

        return @mail($to, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpoHtml, $headers);
    }

    /**
     * Notificar al admin cuando un editor completa todos sus productos
     */
    public static function notificarCompletado(string $editorNombre, string $importacionNombre, int $totalOk, string $adminEmail = ''): void {
        $cfg   = self::config();
        $dest  = $adminEmail ?: $cfg['admin_email'];
        if (!$dest) return;

        $html = self::plantillaBase(
            '✔ Productos completados',
            "<p>El usuario <strong>{$editorNombre}</strong> ha completado la revisión AESAN de
             <strong>{$totalOk} productos</strong> en la importación <em>{$importacionNombre}</em>.</p>
             <p>Ya pueden ser exportados a PrestaShop.</p>",
            'Ver importación'
        );
        self::enviar($dest, "AESAN Checker — {$totalOk} productos completados por {$editorNombre}", $html);
    }

    /**
     * Notificar al editor cuando el admin le asigna una revisión
     */
    public static function notificarAsignacion(string $editorEmail, string $editorNombre, string $importacionNombre, int $total): void {
        $html = self::plantillaBase(
            'Nueva importación para revisar',
            "<p>Hola <strong>{$editorNombre}</strong>,</p>
             <p>Se ha asignado una nueva importación para que completes la información AESAN:
             <strong>{$importacionNombre}</strong> con <strong>{$total} productos</strong>.</p>",
            'Acceder a la aplicación'
        );
        self::enviar($editorEmail, "AESAN Checker — Nueva importación para revisar", $html);
    }

    /**
     * Plantilla HTML base para emails
     */
    private static function plantillaBase(string $titulo, string $contenido, string $btnTexto = ''): string {
        $year = date('Y');
        $btn  = $btnTexto
            ? "<p style='text-align:center;margin-top:24px'>
                 <a href='#' style='background:#1A5276;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold'>{$btnTexto}</a>
               </p>"
            : '';
        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body style='font-family:Arial,sans-serif;background:#f0f2f5;margin:0;padding:20px'>
            <div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)'>
              <div style='background:#1A5276;color:#fff;padding:24px;text-align:center'>
                <h1 style='margin:0;font-size:1.3rem'>🛡 AESAN Checker</h1>
                <p style='margin:4px 0 0;opacity:.8;font-size:.9rem'>{$titulo}</p>
              </div>
              <div style='padding:24px'>
                {$contenido}
                {$btn}
              </div>
              <div style='background:#f8f9fa;padding:12px;text-align:center;font-size:.75rem;color:#888'>
                AESAN Checker — Plan Coordinado 2026 — © {$year}
              </div>
            </div>
          </body></html>";
    }
}
