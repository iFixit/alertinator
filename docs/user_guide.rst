User Guide
==========

Installation
------------

If your project is using git, the recommended approach is to add Alertinator as
a submodule::

    [$]> git submodule add https://github.com/iFixit/alertinator.git
    [$]> git submodule update --init --recursive

A fallback approach is to copy the directory in directly::

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

An :class:`Alertinator` is constructed with one argument, a nested associative
array containing all the information about your alerting system.  Due to PHP's
lack of support for `splatting`_ parameters, the single-array method was chosen
to provide the greatest calling flexibility - you can construct it piece by
piece or all in one go.

``$config`` consists of four parts:

.. _splatting: https://endofline.wordpress.com/2011/01/21/the-strange-ruby-splat/

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
sent.

    'checks' => [
       'checkDB' => ['ops', 'devs'],
    ],

In this case, ``checkDB`` is a global function that throws an
:class:`AlertinatorException` when some alerting threshold is passed.  When
that happens, the alert will be sent out to members of the ``ops`` and ``devs``
:ref:`groups`.

It's not necessarily a good idea to use global function for your alerts.
Correspondingly, alert names can be any PHP `callable`_, e.g.
``AlertChecker::checkDB``.

.. _callable: http://www.php.net/manual/en/language.types.callable.php

.. _groups:

Groups
^^^^^^

Groups allow you to alert a number of people together without having to repeat
their names.

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

Under the hood, these alert methods are directly mapped to method calls on the
:class:`Alertinator` object.  This allows easy extension for additional contact
methods.  For instance, at iFixit we have a contact that looks something like
this::

    'hubot' => [
       'devChatAnnounce' => ['all', Alertinator::ALL],
    ],

We've extended :class:`Alertinator` to add this method::

    class AlertChecker extends Alertinator {
       /**
        * Send $message to $recipient via DevChat.
        */
       protected function devChatAnnounce($recipient, $message) {
          // Code here.
       }
    }

And we construct and call ``alert()`` on our ``AlertChecker`` class instead of
:class:`Alertinator` directly.

