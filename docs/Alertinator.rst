Alertinator API Docs
====================
.. php:class:: AlertinatorException

      The base exception class Alertinator uses.

      Derived exceptions are used to trigger an alert.  The only requirement is
      that they must have a class constant ``bitmask``, which will be ORed
      together with each alertee's notify levels to determine whether a particular
      alerting method will be used.

.. php:class:: AlertinatorNoticeException

   .. php:const:: AlertinatorNoticeException:: bitmask = Alertinator::NOTICE;

.. php:class:: AlertinatorWarningException

   .. php:const:: AlertinatorWarningException:: bitmask = Alertinator::WARNING;

.. php:class:: AlertinatorCriticalException

   .. php:const:: AlertinatorCriticalException:: bitmask = Alertinator::CRITICAL;

.. php:class:: Alertinator

   .. php:const:: Alertinator:: NOTICE = 1;

   .. php:const:: Alertinator:: WARNING = 2;

   .. php:const:: Alertinator:: CRITICAL = 4;

   .. php:const:: Alertinator:: ALL = 7;

   .. php:attr:: $twilio

   .. php:attr:: $checks

   .. php:attr:: $groups

   .. php:attr:: $alertees

   .. php:attr:: $emailSubject

   .. php:attr:: $_twilio

   .. php:method:: Alertinator::__construct()

   .. php:method:: Alertinator::check()

      Run through every check, alerting the appropriate alertees on check
      failure.

      :raises Exception: Rethrows any non-expected Exceptions thrown in the
                         checks.

   .. php:method:: Alertinator::alertGroups()

   .. php:method:: Alertinator::extractAlertees()

      :param iterable $alerteeGroups: An iterable of strings corresponding to
                                      group names in ``$this->groups``.
      :returns: An iterable of strings corresponding to alertee names in
                ``$this->alertees``.

   .. php:method:: Alertinator::alert()

      Alert an alertee.

      :param AlertinatorException $exception: The exception containing
                                              information about the alert.
      :param array $alertee: An array describing an alertee in the format
                             of ``$this->alertees``.

   .. php:method:: Alertinator::email()

      Send an email to ``$address`` with ``$message`` as the body.

   .. php:method:: Alertinator::sms()

      Send an SMS of ``$message`` through Twilio to ``$number``.

   .. php:method:: Alertinator::call()

      Make a phone call through Twilio to ``$number``, with text-to-speech of
      ``$message``.

   .. php:method:: Alertinator::getTwilioSms()

      Return an object capable of sending Twilio SMS messages.

      This function exists partly to ease mocking, and partly to abstract away
      Twilio's deep object inheritance.

   .. php:method:: Alertinator::getTwilioCall()

      Return an object capable of making Twilio calls.

      This function exists partly to ease mocking, and partly to abstract away
      Twilio's deep object inheritance.

   .. php:method:: Alertinator::getTwilio()

      Return a configured :class:`Services_Twilio` object.