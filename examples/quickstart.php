<?php

// Create an address, wait for an email, print the code, delete the address.
//
//   export TMAILOR_API_KEY=tmk_live_...
//   php examples/quickstart.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tmailor\Client;
use Tmailor\Exception\WaitTimeoutException;

$client = new Client(); // reads TMAILOR_API_KEY

$me = $client->me();
printf("Quota left: %d, rate: %d/min\n", $me->limits->quotaRemaining, $me->limits->ratePerMin);

$box = $client->mailboxes->create();
printf("Send an email to %s (waiting up to 2 minutes)...\n", $box->email);
try {
    // `since` is exclusive (receivedAt > since): "- 1" also accepts a message received in the creation second.
    $msg = $box->waitForMessage(timeout: 120, since: ($box->createdAt ?? 0) - 1);
    printf("From:    %s <%s>\n", $msg->from->name, $msg->from->address);
    printf("Subject: %s\n", $msg->subject);
    printf("Code:    %s\n", $msg->otp ?? '(none)');
    foreach (array_slice($msg->links, 0, 5) as $link) {
        printf("Link:    %s\n", $link);
    }
} catch (WaitTimeoutException) {
    echo "No email arrived.\n";
} finally {
    $box->delete();
}
