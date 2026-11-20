<?php

use Illuminate\Support\Facades\Route;

Route::get('product-categories', function () {
    return redirect('/categories');
});

Route::get('category/{slug}', function ($slug) {
    return redirect("/product-categories/{$slug}");
});
