<?php

require 'Alertinator.php';

/**
 * This mocker class exists solely to allow us to test protected methods in
 * Alertinator.
 */
class AlertinatorMocker extends Alertinator {
   public function extractAlertees($alerteeGroups) {
      return parent::extractAlertees($alerteeGroups);
   }
}

class AlertinatorTest extends PHPUnit_Framework_TestCase {
   protected function setUp() {
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
}

