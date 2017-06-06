<?php

return [

    /**
     * Enter models which can be commented upon.
     */
    'content' => [
        // App\Post::class,
    ],

    /**
     * Enter your user model.
     */
    'user_model' => App\User::class,

    /**
     * Get the path to the login route.
     */
    'login_path' => '/login',

    /**
     * Remove nested data property of user object
     * e.g. instead of user: { data: { id: 1 , ... }} it will be
     *      user: { id:1, ... } (if removeDataProperty = true)
     */
    'remove_data_property' => true,

    /**
     * Enable comments routing
     * required so it can be disabled and handled in application
     * e.g. apply the routes in api route-group
     *
     */
    'enable_routing' => true
];
