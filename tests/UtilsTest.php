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
    $this->assertEqual('2019-12-01', $date->format('Y-m-d'));

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
    // We only allow start dates in 31-day months.
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

  /**
   * Test a start date late in the month.
   */
  public function testGetStartDatePassingFebrurary() {
    $now = new \DateTimeImmutable('2019-09-23 14:50:58.000000');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'start_date' => new \DateTime('2019-10-30 00:00:00.000000'),
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2019-10-30', $date->format('Y-m-d'));

    $now = new \DateTimeImmutable('2020-02-10');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'start_date' => new \DateTime('2020-02-29'),
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2020-02-29', $date->format('Y-m-d'));
  }

  /**
   * Test a calculating the start date with constraint on September 31st.
   */
  public function testGetStartDateSep31() {
    $now = new \DateTimeImmutable('2020-02-15');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'yearly',
      'day_of_month' => '31',
      'month' => '9',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2020-09-30', $date->format('Y-m-d'));
  }

  /**
   * Test a calculating the start date with constraint on September 31st.
   */
  public function testGetStartDateFeb31() {
    $now = new \DateTimeImmutable('2019-09-15');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'yearly',
      'day_of_month' => '31',
      'month' => '2',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2020-02-29', $date->format('Y-m-d'));
  }

  /**
   * Test complex combination.
   */
  public function testGetStartDateFarAwayStartDate() {
    $this->expectException(\UnexpectedValueException::class);
    $now = new \DateTimeImmutable('2019-09-15');
    $line_item = $this->lineItemStub([], [
      'interval_unit' => 'monthly',
      'interval_value' => '3',
      'start_date' => new \DateTime('2020-01-10'),
      'day_of_month' => '1',
      'month' => '9',
    ]);
    $date = Utils::getStartDate($line_item, $now);
    $this->assertEqual('2020-03-01', $date->format('Y-m-d'));
  }

  /**
   * Test creating a subscription from a payment.
   */
  public function testGenerateSubscriptions() {
    $start_date = (new \DateTimeImmutable('', new \DateTimeZone('UTC')))->modify('+10 day');
    $payment = new \Payment([
      'description' => 'test payment',
      'currency_code' => 'EUR',
    ]);
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'item1',
      'description' => 'Item 1 test',
      'amount' => 3,
      'quantity' => 5,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
        'interval_value' => 1,
        'start_date' => $start_date,
      ],
    ]));
    $options = Utils::generateSubscriptions($payment);
    $this->assertEqual([[
      'subscription'=> [
        'off_session' => TRUE,
        'payment_behavior' => 'error_if_incomplete',
        'prorate' => FALSE,
        'billing_cycle_anchor' => $start_date->getTimeStamp(),
        'items' => [[
          'plan' => '1-monthly-item1-EUR',
          'quantity' => 15,
        ]],
      ],
      'plan' => [
        'id' => '1-monthly-item1-EUR',
        'amount' => 100,
        'currency' => 'EUR',
        'interval' => 'month',
        'interval_count' => 1,
        'nickname' => '1 monthly Item 1 test in EUR',
        'product' => 'item1',
      ],
      'product' => [
        'id' => 'item1',
        'name' => 'Item 1 test',
        'statement_descriptor' => 'Item 1 test',
      ],
    ]], $options);
  }

  /**
   * Test preparing a string to use as statement descriptor.
   */
  public function testStatementDescriptor() {
    // No transliteration is happening at the moment.
    $s = 'Mäusefüßchengröße';
    $output = Utils::getStatementDescriptor($s);
    $this->assertEqual($output, 'Mäusefüßchengröße');
    // The output does not contain characters Stripe wouldn’t accept.
    $s = '<, >, \, ", \'';
    $output = Utils::getStatementDescriptor($s);
    foreach (['<', '>', '\\', '"', '\''] as $c) {
      $this->assertFalse(strpos($output, $c));
    }
    // If the output is too long, we don’t want to set a statement descriptor.
    $s = 'This is a somewhat longer description.';
    $this->assertNull(Utils::getStatementDescriptor($s));
  }

}
