<?php

/*
|--------------------------------------------------------------------------
| Receipt share links (finish run round 2, decision 6)
|--------------------------------------------------------------------------
|
| Staff holding `payments:view` make a link that opens one payment receipt
| without signing in, masked, for 30 days. Off by default: while it is off
| every share link route answers 404, the staff ones and the public one
| alike. On for the staging APIs only, through a switch PR.
|
*/

return [
    'enabled' => filter_var(env('RECEIPT_SHARE_LINKS_ENABLED', false), FILTER_VALIDATE_BOOL),
];
