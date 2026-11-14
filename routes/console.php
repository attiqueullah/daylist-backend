<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('daylist:purge-deleted')->dailyAt('03:15');
