<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Skin catalog
    |--------------------------------------------------------------------------
    |
    | Statik JSON katalog (Decision 3): weapon defindex, paintkit, sticker,
    | agent, keychain ve music kit isimlerini tutan dosyalar. Dosya adları
    | CS2_Skin-fork EconService.cs:97 dump isimleriyle birebir – panel bu
    | dosyaları okur, asla üretmez. Dosya eksikse ilgili endpoint boş liste
    | döner; varlığı şart değildir.
    |
    */

    'path' => env('CATALOG_PATH', storage_path('app/catalog')),

    'ttl' => 300,
];