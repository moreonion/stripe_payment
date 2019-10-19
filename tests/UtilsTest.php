<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

/**
 * Test utility functions.
 */
class UtilsTest extends DrupalUnitTestCase {

  /**
   * Create a mock line item to get values from.
   */
  protected function lineItemStub($values, $recurrence = []) {
    $line_item = $this->createMock(\PaymentLineItem::class);
    foreach ($values as $prop => $value) {
      $line_item->$prop = $value;
    }
    $line_item->recurrence = (object) $recurrence;
    return $line_item;
  }

  /**
   * Test the recurrence start date is calculated correctly.
   */
  public function testGetStartDate() {
    $now = new \DateTimeImmutable('2019-09-15');
    $next_month = new \DateTime('2019-10-15');

    // No date values:
    $line_item = $this->lineItemStub([], ['interval_unit' => 'monthly']);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEmpty($date);

    // Day of month:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'day_of_month' => '5',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('05', $date->format('d'));
    $this->assertTrue($date > $now);

    // Month:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'yearly',
      'month' => '5',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('05', $date->format('m'));
    $this->assertTrue($date > $now);

    // Start date in the future:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'yearly',
      'start_date' => $next_month,
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEquals($date->format('Y-m-d'), $next_month->format('Y-m-d'));

    // Start date in the past:
    $last_month = new \DateTime('2019-08-15');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'yearly',
      'start_date' => $last_month,
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertNotEquals($date->format('Y-m-d'), $last_month->format('Y-m-d'));
    $this->assertTrue($date > $now);

    // Start date + day of month + interval value:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'interval_value' => '3',
      'start_date' => new \DateTime('2019-10-10'),
      'day_of_month' => '1',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEquals($date->format('Y-m-d'), '2019-11-01');

    // Start date + day of month + month + interval value:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'interval_value' => '3',
      'start_date' => new \DateTime('2019-10-10'),
      'day_of_month' => '1',
      'month' => '9',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEquals($date->format('Y-m-d'), '2019-12-01');

    // Start date + day of month + far away month + interval value:
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'interval_value' => '3',
      'start_date' => new \DateTime('2020-01-10'),
      'day_of_month' => '1',
      'month' => '9',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEquals($date->format('Y-m-d'), '2020-03-01');

    // Weekly: everything but start date shouldn't matter.
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'weekly',
      'interval_value' => '3',
      'start_date' => new \DateTime('2019-10-10'),
      'day_of_month' => '1',
      'month' => '9',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEquals($date->format('Y-m-d'), '2019-10-10');
  }

  /**
   * Test getting start date with end of month payments.
   */
  public function testGetStartEndOfMonth() {
    $now = new \DateTimeImmutable('2019-09-15');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'day_of_month' => '-1',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2019-09-30', $date->format('Y-m-d'));

    // Tricky value as there is a Feb 29.
    $now = new \DateTimeImmutable('2020-02-15');
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2020-02-29', $date->format('Y-m-d'));
  }

}
