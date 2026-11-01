<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who else is here
    |--------------------------------------------------------------------------
    |
    | On an entry's edit page, show the people who have the same entry open,
    | and warn when one of them saves it.
    |
    */

    'presence' => env('HIBIRA_STATAMIC_PRESENCE', true),

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    |
    | Comments on entries, for everyone who may view the entry. They are
    | stored as one JSON file per entry in this folder, so no database is
    | needed. Commit the folder if you want comments to travel with your
    | content.
    |
    */

    'comments' => [
        'enabled' => env('HIBIRA_STATAMIC_COMMENTS', true),
        'path' => storage_path('statamic/hibira-comments'),
        'max_length' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live preview on other devices
    |--------------------------------------------------------------------------
    |
    | A live preview link opened on a phone or in another browser reloads
    | when the entry is changed in the control panel. The link stays valid
    | as long as Statamic's live preview token (about an hour of inactivity).
    |
    */

    'live_preview' => env('HIBIRA_STATAMIC_LIVE_PREVIEW', true),

    /*
    |--------------------------------------------------------------------------
    | Statamic's own broadcasting
    |--------------------------------------------------------------------------
    |
    | With BROADCAST_CONNECTION=hibira, point the control panel's own Echo
    | client at Hibira as well, so addons that use it (such as
    | statamic/collaboration) work without more setup.
    |
    */

    'control_panel_echo' => env('HIBIRA_STATAMIC_CP_ECHO', true),

];
