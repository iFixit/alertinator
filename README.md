Simplistic team-based alerting through email, sms and phone calls.

[![Build Status](https://travis-ci.org/iFixit/alertinator.png?branch=master)](https://travis-ci.org/iFixit/alertinator)

# Alertinator

Alertinator makes it easy to notify the appropriate people when something's
gone wrong.  Alerts only get sent to the right team, and alertees can configure
which alerting methods work for them, so you wake up just the right number of
people to keep things running smoothly without running into alert fatigue.

## Motivation

For a long time at [iFixit], we've used [Alertra] for alerting.  While Alertra
is designed to check merely for site uptime, a web request from Alertra would
trigger a few additional checks (time since last order, number of errors in the
last five minutes).  A failure in any of those checks would produce a 5xx
status code, which Alertra interprets as a "site down" error, triggering an
alert.

This system works, but lacks sophistication.  In particular, we had to think
very carefully about our alerts, because they were "heavy" - a failed check
lead to phone calls to the entire set of on-call personnel.  This lead to a
very small set of high-priority checks, with plenty of false negatives, a
number of predictive alerts relegated to email that was often not checked over
the weekend, and periods of alert fatigue in our on-call staff.

[PagerDuty] provides an excellent system to deal with these problems.  However,
one of the benefits of Alertra is the low price - $10/month.  With an increase
in subject-specific on-call staff our current system didn't allow, PagerDuty's
per-user pricing wound up around $400/month - a significant cost.

Alertinator, then, is "PagerDuty-lite" - a system that fulfills the middle
ground between Alertra and PagerDuty.  While you won't get the full set of
features PagerDuty offers, Twilio's low prices allow Alertinator to offer a
reasonable subset of features at a fraction of the cost.

[iFixit]: http://www.ifixit.com
[Alertra]: http://www.alertra.com/
[PagerDuty]: http://www.pagerduty.com/

## Usage

    <?php
    
    require 'path/to/Alertinator.php';
    
    function alertFatigue() {
       throw new AlertinatorCriticalException(
          'Some people just want to watch the world burn.'
       );
    }
    
    $config = [
       'twilio' => [
          'fromNumber' => '+12345678901',
          'accountSid' => '[long string]',
          'authToken' => '[another long string]',
       ],
       'checks' => [
          'alertFatigue' => ['default'],
       ],
       'groups' => [
          'default' => [
             'james',
          ],
          'allClear' => [
             'james',
          ],
       ],
       'alertees' => [
          'james' => [
             'email' => ['james@example.com', Alertinator::NOTICE | Alertinator::WARNING],
             'sms' => ['1234567890', Alertinator::WARNING],
             'call' => ['1234567890', Alertinator::CRITICAL],
          ],
       ],
    ];
    (new Alertinator($config))->check();

For more advanced usage, including using alert thresholds, see the [user guide](https://github.com/ifixit/alertinator/blob/master/docs/user_guide.rst).

## License

Alertinator is available under the LGPL 3.0, which means that while any code
extending or using Alertinator may remain private, your modifications to
Alertinator itself must also be available under the LGPL.

Please see [this human-readable summary][tldrlegal] and the [LICENSE] file for
more information.

[tldrlegal]: http://www.tldrlegal.com/l/LGPL3
[LICENSE]: LICENSE

