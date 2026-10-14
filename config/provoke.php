<?php

return [

    /*
    | Default superadmin created by AdminUserSeeder. Leave ADMIN_PASSWORD empty to
    | have a random password generated (and printed once) at seed time.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Provoke Now Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@provokenow.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
