<?php

/*
|--------------------------------------------------------------------------
| Central homepage — Entrepreneur & Brand Recognition Platform
|--------------------------------------------------------------------------
|
| The homepage (HomeController) currently renders sample content from
| App\Support\Home\Showcase. While `showcase_preview` is true a visible
| "sample preview" notice is shown and vote buttons explain that votes are
| not recorded yet — so no visitor mistakes placeholder brands or numbers
| for real ones. Flip it off only once real data replaces the showcase.
|
*/

return [
    'showcase_preview' => env('PLATFORM_SHOWCASE_PREVIEW', true),
];
