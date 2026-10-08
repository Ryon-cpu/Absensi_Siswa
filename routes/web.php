<?php
use Illuminate\Support\Facades\Route;
Route::view('/', 'app');
Route::view('/login', 'app');
Route::view('/dashboard', 'app');
Route::view('/students', 'app');
Route::view('/classes', 'app');
Route::view('/teachers', 'app');
Route::view('/class-assignments', 'app');
Route::view('/attendance', 'app');
Route::view('/teacher/classes', 'app');
Route::view('/history', 'app');
Route::view('/reports', 'app');
Route::view('/{path}', 'app')->where('path', '^(?!api(?:/|$)).*');
