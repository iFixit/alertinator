User Guide
==========

Installation
------------

If your project is using git, the recommended approach is to add Alertinator as
a submodule::

    [$]> git submodule add https://github.com/iFixit/alertinator.git
    [$]> git submodule update --init --recursive

It's still recommended to use git to download the project, even if you're not
using it for your overlying project::

    [$]> git clone --recursive https://github.com/iFixit/alertinator.git
    [$]> find alertinator -name '.git' -exec rm -rf {} \;

If you don't have git installed on your system, a fallback approach is to copy
the directory in directly::

    [$]> wget 'https://github.com/iFixit/alertinator/archive/master.tar.gz' -O alertinator.tar.gz
    [$]> tar xf alertinator.tar.gz
    [$]> cd alertinator-master
    [$]> rmdir twilio-php
    [$]> wget 'https://github.com/twilio/twilio-php/archive/master.tar.gz' -O twilio-php.tar.gz
    [$]> tar xf twilio-php.tar.gz
    [$]> mv twilio-php-master twilio-php

Be aware that the master version of twilio-php may not be tested with
Alertinator.

Usage
-----

When you want to check your alerts, call ``check()`` on an :class:`Alertinator`
object::

    // Check alerts every 10 seconds.
    while (true) {
       $alertinator->check();
       sleep(10);
    }

We want to provide an alerting system that is both cheap and easy to use - it
should be useful with very little work on your part, but easy to customize for
your specific needs without needing to modify Alertinator itself.  In
particular, this philosophy extends to the only part of Alertinator that costs
money (the Twilio integration) - it's easy to swap it out for an alternative,
or even ignore it altogether.

An :class:`Alertinator` is constructed with one argument, a nested associative
array containing all the information about your alerting system:

``$config`` consists of four parts:

Twilio
^^^^^^

The ``twilio`` key should refer to an associate array with three entries::

    'twilio' => [
       'fromNumber' => '+12345678901',
       'accountSid' => '[long string]',
       'authToken' => '[another long string]',
    ],

Sign into your Twilio account and view `your list of assigned phone numbers`_.
Set ``fromNumber`` to the number from which your calls and SMSs will originate.

Your account SID and auth token are used to identify and authorize your account
- keep these secret!  You can find them on `the main Twilio dashboard`_.

Alertinator will function just fine on a (free) demo Twilio account.  It's
recommended you use one not just while evaluating Alertinator, but always in
your development environment.

.. _your list of assigned phone numbers: https://www.twilio.com/user/account/phone-numbers/incoming
.. _the main Twilio dashboard: https://www.twilio.com/user/account

.. _checks:

Checks
^^^^^^

``checks`` determines which alerts are checked and to whom notifications are
sent. Optionally, you can define an alert threshold (useful for twitchy checks).
If you define an alert threshold, you should also define an all-clear threshold.
If you use thresholds, an alert logger will be used for alert persistence. More
information on the logger interface is in a section below::

    // Simple implementation:
    'checks' => [
       'checkDB' => ['ops', 'devs'],
    ],

In this case, ``checkDB`` is a global function that throws an
:class:`AlertinatorException` when some alerting threshold is passed.  When
that happens, the alert will be sent out to members of the ``ops`` and ``devs``
:ref:`groups`::

    // Complex implementation, with alert thresholds:
    'checks' => [
        'checkDB' => [
            'groups' => ['ops'],
            'alertAfter'  => 5,
            'clearAfter'  => 2,
            'remindEvery' => 1,
        ],
    ],

In this case, ``checkDB`` is still the check function, and when the alert
exception is thrown, the alert will be sent to the ``ops`` group after the
``afterAlert`` threshold is met. Also, once the check passes ``clearAfter``
times in a row, an all-clear alert will be sent. Finally, the ``remindEvery``
threshold limits reminders to only every X errors after the first alert is
sent.

It's not necessarily a good idea to use global function for your alerts.
Correspondingly, alert names can be any PHP `callable`_, e.g.
``AlertChecker::checkDB``.

.. _callable: http://www.php.net/manual/en/language.types.callable.php

.. _groups:

Groups
^^^^^^

Groups allow you to alert a number of people together without having to repeat
their names::

    'groups' => [
       'ops' => ['alice'],
       'devs' => ['bob', 'charlie'],
    ],

The keys of the ``groups`` associative array represent the groups' names; these
are how you'll refer to the groups in the :ref:`checks`.  The values are arrays
of :ref:`alertees` belonging to the group.

.. _alertees:

Alertees
^^^^^^^^

If an alert is triggered but no one's around to hear it, your boss will let you
know the next morning whether the system broke (hint: the answer is always
yes).

``alertees`` comprise the most complex of the data structures in ``$config``.
Here's an example with two people::

    'alertees' => [
       'alice' => [
          'email' => ['alice@example.com', Alertinator::ALL],
          'sms' => ['1234567890', Alertinator::WARNING],
          'call' => ['1234567890', Altertinator::CRITICAL],
       ],
       'bob' => [
          'email' => ['bob@example.com', Alertinator::ALL],
       ],
    ],

Here we see that both Alice and Bob want to receive emails about all the
alerts, but only Alice wants to receive SMSs and phone calls (when the alerts
are of sufficient severity).  You'll notice that we can just leave out any
definitions for contact methods Bob doesn't want without causing an error in
Alertinator.

Each key in these arrays should be the name of a method in the
:class:`Alertinator` object.  Under the hood, we loop through the keys and call
the same-named method, passing the first tuple element and the alert-generated
message as parameters.  This allows easy extension for additional contact
methods.  For instance, at iFixit we have a contact that looks something like
this::

    'hubot' => [
       'devChatAnnounce' => ['all', Alertinator::ALL],
    ],

We've extended :class:`Alertinator` to add this method::

    class AlertChecker extends Alertinator {
       
       // Send $message to $recipient via DevChat. 
       protected function devChatAnnounce($recipient, $message) {
          // Code here.
       }
    }

And we construct and call ``alert()`` on our ``AlertChecker`` class instead of
:class:`Alertinator` directly.

Notification thresholds
^^^^^^^^

Notification thresholds are definable on a per-check level. As in the example
above, you define your thresholds like this::

    // With alert thresholds:
    'checks' => [
      'checkDB' => [
         'groups' => ['ops'],
         'alertAfter' => 5,
         'clearAfter' => 2,
         ],
      ],
      
``alertAfter``: send alerts after this many sequential failures. This is counted
in a row: any successes on the same check before the alert threshold is met will
reset the alert counter silently.

``clearAfter``: send an all-clear message after this many sequential successes.
Note: the all-clear message will send at the ``AlertinatorCriticalException``
level, no matter what level the initial exception was.

Alert persistence adaptor
^^^^^^^^

Using alert thresholds requires a persistence layer. Alertinator by default uses
the filesystem and PHP's tmp directory for this purpose. You can define your own
interface (for example, if you'd like to use MySQL) by implementing the
``alertLogger`` interface.

If you don't use notification thresholds, this section doesn't apply to you.
