<?php
declare(strict_types=1);
?>
        </div>
    </main>
    <footer class="app-footer">
        <p>Operasional Parkir &middot; <?= e((new DateTimeImmutable())->format('Y')) ?></p>
    </footer>
    <dialog class="confirm-dialog" id="delete-confirmation" aria-labelledby="delete-confirmation-title">
        <h2 id="delete-confirmation-title">Konfirmasi penghapusan</h2>
        <p data-confirmation-message>Data yang dihapus tidak dapat dipulihkan.</p>
        <div class="confirm-dialog-actions">
            <button class="button button-secondary" type="button" data-confirm-cancel>Batalkan</button>
            <button class="button" type="button" data-confirm-accept>Hapus data</button>
        </div>
    </dialog>
    <script src="<?= e(app_url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
