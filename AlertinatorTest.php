<?php

// Make sure *any* failure is actually reported as a failure.
error_reporting(E_ALL);

require 'Alertinator.php';

use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Api\V2010\Account\CallList;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\CallInstance;

/**
 * This mocker class exists to allow us to test protected and
 * external-service-using in Alertinator.
 */
class AlertinatorMocker extends Alertinator {
   public function extractAlertees(iterable $alerteeGroups): array {
      return parent::extractAlertees($alerteeGroups);
   }

   public function alert(AlertinatorException $exception, iterable $alertee, string $textPrefix = null): void {
      parent::alert($exception, $alertee, $textPrefix);
   }

   public function email(string $address, string $message): void {
      echo "Sending message $message to $address via email.\n";
   }

   public function getTwilioSms(): MessageList {
      return new TwilioMessagesMocker();
   }

   public function getTwilioCall(): CallList {
      return new TwilioCallsMocker();
   }

   public function getTwiML(string $message): VoiceResponse {
      return parent::getTwiML($message);
   }
}

class MockMessage extends MessageInstance {
   public function __construct() {}
}
class MockCall extends CallInstance {
   public function __construct() {}
}

class TwilioMessagesMocker extends MessageList {
   public function __construct() {}

   public function create(string $toNumber, array $options = []): MessageInstance {
      $from = $options['from'];
      $message = $options['body'];
      echo "Sending message $message to $toNumber via sms.\n";
      return new MockMessage();
   }
}

class TwilioCallsMocker extends CallList {
   public function __construct() {}

   public function create(string $to, string $from, array $options = []): CallInstance {
      $twiml = $options['Twiml'];
      echo "Sending message with TwiML $twiml to $to via call.\n";
      return new MockCall();
   }
}

class AlertinatorTest extends PHPUnit\Framework\TestCase {
   private $alertinator;

   protected function setUp(): void {
      date_default_timezone_set("America/Los_Angeles");
      // Create an Alertinator with just enough config to construct.
      $this->alertinator = new AlertinatorMocker(
         [
            'twilio' => [],
            'checks' => [],
            'groups' => [],
            'alertees' => [],
         ]
      );
   }

   public function test_extractAlertees() {
      // The simplest possible case.
      $this->assertEquals(
         [],
         $this->alertinator->extractAlertees([])
      );

      // Just one group with one member.
      $this->alertinator->groups = ['one' => ['foo']];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one'])
      );

      // Multiple groups.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['bar'],
      ];
      $this->assertEquals(
         ['foo', 'bar'],
         $this->alertinator->extractAlertees(['one', 'two'])
      );

      // Unused groups.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['bar'],
      ];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one'])
      );

      // Uniqueness.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['foo'],
      ];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one', 'two'])
      );

      // Multiple members.
      $this->alertinator->groups = ['one' => ['foo', 'bar', 'baz']];
      $this->assertEquals(
         ['foo', 'bar', 'baz'],
         $this->alertinator->extractAlertees(['one'])
      );
   }

   public function test_alert() {
      $message = 'foobaz';
      $fromNumber = '1111111111';
      $toNumber = '2222222222';
      $toEmail = 'foo@example.com';

      $this->alertinator->twilio['fromNumber'] = $fromNumber;

      // One level that's exactly right.
      $alertees = ['email' => [$toEmail, Alertinator::WARNING]];
      $this->expectOutputEquals(
         "Sending message $message to $toEmail via email.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException($message), $alertees]
      );

      // Non-matching levels.
      $this->expectOutputEquals(
         '',
         [$this->alertinator, 'alert'],
         [new AlertinatorCriticalException($message), $alertees]
      );

      // Multiple levels associated with an alerting method.
      $alertees = ['email' => [
         $toEmail, Alertinator::WARNING | Alertinator::CRITICAL
      ]];
      $this->expectOutputEquals(
         "Sending message $message to $toEmail via email.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException($message), $alertees]
      );

      // Multiple methods.
      $alertees = [
         'email' => [$toEmail, Alertinator::WARNING],
         'sms' => [$toNumber, Alertinator::WARNING],
         'call' => [$toNumber, Alertinator::WARNING],
      ];

      $twiml = $this->alertinator->getTwiML($message);
      $this->assertStringContainsString($message, $twiml);
      $this->expectOutputEquals(
         "Sending message $message to $toEmail via email.\n"
         . "Sending message $message to $toNumber via sms.\n"
         . "Sending message with TwiML $twiml to +1$toNumber via call.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException($message), $alertees]
      );
   }

   /**
    * Test the default storage interface.
   */
   public function test_file_interface() {
      $fl = new fileLogger();
      // Nothing logged, this should be empty:
      $this->assertEmpty($fl->isInAlert('foofail'));

      // Write a failure, see if it was written:
      $fl->writeAlert('foofail', 0);
      $log = $fl->readAlerts('foofail');
      $this->assertNotEquals('barfail', $log[0]['check']);
      $this->assertEquals('foofail', $log[0]['check']);
      $this->assertEquals(0, $log[0]['status']);

      // Write a success, see if it was written:
      $fl->writeAlert('foofail', 1);
      $log = $fl->readAlerts('foofail');
      $this->assertNotEquals('barfail', $log[1]['check']);
      $this->assertEquals('foofail', $log[1]['check']);
      $this->assertEquals(1, $log[1]['status']);

      // Reset alerts, confirm that the log is empty:
      $fl->resetAlerts('foofail');
      $this->assertEmpty($fl->isInAlert('foofail'));

      // Since order is important for determining alert clears, see if slamming
      // the interface breaks things.
      $test_values = array();
      for ($i = 0; $i < 100; $i++) {
         $rand = rand(0, 1);
         $test_values[] = $rand;
         $fl->writeAlert('megacount', $rand);
      }
      $log = $fl->readAlerts('megacount');
      $this->assertEquals(100, count($log));
      foreach ($test_values as $k => $v) {
         $this->assertEquals($v, $log[$k]['status']);
      }

      // Reset alerts, confirm again that the log is empty:
      $fl->resetAlerts('megacount');
      $this->assertEmpty($fl->isInAlert('megacount'));
   }

   /**
    * Test the threshold functionality.
   */
   public function test_error_thresholds() {
      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => [
            'AlertinatorTest::failFiveTimes' => [
               'groups' => ['default'],
               'alertAfter' => 5,
               'clearAfter' => 2,
               ],
            ],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);
      $alertinator->logger->safelyResetAlerts(key($alertinator->checks));

      // The first 4 alerts should do nothing...
      $this->expectOutputEquals('', [$alertinator, 'check']);
      $this->expectOutputEquals('', [$alertinator, 'check']);
      $this->expectOutputEquals('', [$alertinator, 'check']);
      $this->expectOutputEquals('', [$alertinator, 'check']);

      // The 5th alert should fire an email.
      $this->expectOutputStartsAndEndsWith(
         "Sending message Threshold of 5 reached at",
         ": Fail Five Times Test to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      // Clear #1...
      $this->expectOutputString('');
      $alertinator->check();

      // The 2nd clear should fire an email.
      $this->expectOutputStartsAndEndsWith(
         "Sending message The alert 'AlertinatorTest::failFiveTimes' was cleared at",
         "to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      // Make sure everything was deleted.
      $this->assertEmpty($alertinator->logger->isInAlert('AlertinatorTest::failFiveTimes'));


      // Now let's simulate a failure state that "bounces" between fail and
      // success:
      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => [
            'AlertinatorTest::failBouncer' => [
               'groups' => ['default'],
               'alertAfter' => 10,
               'clearAfter' => 5,
               ],
            ],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);
      $alertinator->logger->safelyResetAlerts(key($alertinator->checks));

      // TODO: As you can see, this state is (maybe?) not handled well.
      // @see Alertinator::notifyClear()
      for ($i = 0; $i < 19; $i++) {
         $this->expectOutputString('', [$alertinator, 'check']);
      }
   }

   public function test_check_errors() {
      $message = "Internal failure in check:\n" .
                 "Use of undefined constant sdf - assumed 'sdf'";

      $this->expectWarning();
      $this->expectWarningMessageMatches("/sdf/");

      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => ['AlertinatorTest::buggyCheck' => ['default']],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);

      try {
         $this->expectOutputEquals(
            "Sending message $message to alice@example.com via email.\n",
            [$alertinator, 'check']
         );
      } finally {
         ob_end_clean();
      }
   }

   /**
    * Test notification intervals
    */
   public function test_alert_reminders() {
      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => [
            'AlertinatorTest::failFourTimes' => [
               'groups' => ['default'],
               'alertAfter' => 3,
               'clearAfter' => 2,
               'remindEvery' => 1,
               ],
            ],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);
      $alertinator->logger->safelyResetAlerts(key($alertinator->checks));

      $this->expectOutputEquals('', [$alertinator, 'check']);
      $this->expectOutputEquals('', [$alertinator, 'check']);

      $this->expectOutputStartsAndEndsWith(
         "Sending message Threshold of 3 reached at",
         ": Fail Four Times Test to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputStartsAndEndsWith(
         "Sending message Threshold of 3 reached at",
         " (reminding every 1 fails): Fail Four Times Test to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputEquals('', [$alertinator, 'check']);

      $this->expectOutputStartsAndEndsWith(
         "Sending message The alert 'AlertinatorTest::failFourTimes' was cleared at",
         "to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputEquals('', [$alertinator, 'check']);

      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => [
            'AlertinatorTest::failTripleBouncer' => [
               'groups' => ['default'],
               'alertAfter' => 1,
               'clearAfter' => 2,
               'remindEvery' => 2,
               ],
            ],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);
      $alertinator->logger->safelyResetAlerts(key($alertinator->checks));

      $this->expectOutputStartsAndEndsWith(
         "Sending message Threshold of 1 reached at",
         ": Fail 3Bouncer to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputEquals('', [$alertinator, 'check']);

      $this->expectOutputStartsAndEndsWith(
         "Sending message Threshold of 1 reached at",
         " (reminding every 2 fails): Fail 3Bouncer to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputEquals('', [$alertinator, 'check']);

      $this->expectOutputStartsAndEndsWith(
         "Sending message The alert 'AlertinatorTest::failTripleBouncer' was cleared at",
         "to alice@example.com via email.\n",
         [$alertinator, 'check']
      );

      $this->expectOutputEquals('', [$alertinator, 'check']);

   }

   /**
    * Fail 4 times, then pass indefinitely.
    */

   public static function failFourTimes() {
      static $counter = 0;

      if ($counter++ < 4) {
         throw new AlertinatorCriticalException('Fail Four Times Test');
      }
   }

   /**
    * Fail 5 times, then pass indefinitely.
    */

   public static function failFiveTimes() {
      static $counter = 0;

      if ($counter++ < 5) {
         throw new AlertinatorCriticalException('Fail Five Times Test');
      }
   }

   /**
    * Alternates between 3 fail/3 passes for 4 6-check cycles, then passes indefinitely.
   */
   public static function failTripleBouncer() {
      static $counter = 0;
      // By setting to true we can expect the first checks to be false because
      // 0 % 3 == 0 and the initial value is flipped on first check.
      static $passing = true;

      if (!($counter % 3)) {
         $passing = !$passing;
      }

      if ($counter++ < 24 && !$passing) {
         throw new AlertinatorCriticalException('Fail 3Bouncer');
      }
   }

   /**
    * Alternates between fail/pass 25 times, then passes indefinitely.
   */
   public static function failBouncer() {
      static $counter = 0;

      if (!($counter % 2) && $counter++ < 25) {
         throw new AlertinatorCriticalException('Fail Bouncer');
      }
   }

   /**
    * This is an example of an alert-checking function with an error in it.
    */
   public static function buggyCheck() {
      trigger_error('sdf', E_USER_WARNING);
   }

   /**
    * Report an error if the output of `$callable` is not `$expected`. This
    * provides more flexibility over PHPUnit's `expectOutputString()`.
    */
   private function expectOutputEquals($expected, $callable, $params=[]) {
      ob_start();
      call_user_func_array($callable, $params);
      $output = ob_get_clean();

      $this->assertEquals($expected, $output);
   }

   /**
    * Seems silly, but failure and reset messages have timestamps in the middle
    * of them which would be cumbersome to persist. Instead, we can check the
    * start and end of the message (effectively bypassing the timestamp).
    * Stop right there, you're thinking "why not a regex?"
    * This is why: http://regex.info/blog/2006-09-15/247
   */
   private function expectOutputStartsAndEndsWith($expectedStart, $expectedEnd, $callable, $params=[]) {
      ob_start();
      call_user_func_array($callable, $params);
      $output = ob_get_contents();
      ob_end_clean();

      $this->assertStringStartsWith($expectedStart, $output);
      $this->assertStringEndsWith($expectedEnd, $output);
   }
}

