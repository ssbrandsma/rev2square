<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BelongsToProject;

abstract class Controller
{
    use BelongsToProject;
}
