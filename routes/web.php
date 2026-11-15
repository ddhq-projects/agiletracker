<?php

use Illuminate\Support\Facades\Route;

// The single status-driven Kanban page became a list of boards. Keep old
// bookmarks working instead of returning a 404.
Route::redirect('/kanban-board', '/boards');
