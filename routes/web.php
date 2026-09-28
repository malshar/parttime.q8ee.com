<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
// Temporary named routes so the home view renders; replaced in Task 4.
Route::view('/register', 'home')->name('register');
Route::view('/login', 'home')->name('login');
