<?php
/**
 * Respuesta de un alta rápida guardada dentro de una ventana ("quickCreate"): app.js avisa a la página
 * que la abrió, que cierra la ventana y recarga sus tablas.
 *
 * @var \Quasar\Core\View $this
 * @var array{module:string,id:string,message:string,url:string} $done
 */
?>
<div class="qs-modal-done" data-modal-done="<?= $this->json($done) ?>"><?= $this->e($done['message']) ?></div>
