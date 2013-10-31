Alertinator
===========

Alertinator provides simplistic team-based alerting through email, sms and
phone calls. ::

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
             'you',
          ],
       ],
       'alertees' => [
          'you' => [
             'email' => ['you@example.com', Alertinator::NOTICE | Alertinator::WARNING],
             'sms' => ['1234567890', Alertinator::WARNING],
             'call' => ['1234567890', Alertinator::CRITICAL],
          ],
       ],
    ];
    (new Alertinator($config))->check();

.. toctree::
   :maxdepth: 3

   Alertinator

Indices and tables
==================

* :ref:`genindex`
* :ref:`modindex`
* :ref:`search`

