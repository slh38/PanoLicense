<?php

use Illuminate\Support\Facades\Route;

/*
 * React panel aynı domain altında sunulur. Statik dosyalar (public/spa/assets) doğrudan
 * web sunucusundan gelir; /api dışındaki tüm sayfa yolları panelin index.html dosyasına düşer.
 */
Route::fallback(function () {
    abort_if(request()->is('api/*'), 404);

    $index = config('panolicense.spa_index');
    abort_unless(is_file($index), 404, 'Panel build bulunamadı (npm run build:deploy).');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
});
