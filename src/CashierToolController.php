<?php

namespace Themsaid\CashierTool;

use Laravel\Cashier\Plan\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Routing\Controller;
use Laravel\Cashier\Plan\Contracts\PlanRepository;
use Laravel\Cashier\Subscription as MollieStripeSubscription;

class CashierToolController extends Controller
{
    /**
     * The model used by Stripe.
     *
     * @var string
     */
    public $stripeModel;

    /**
     * The subscription name.
     *
     * @var string
     */
    public $subscriptionName;

    /**
     * Create a new controller instance.
     *
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->middleware(function ($request, $next) use ($config) {

            $this->stripeModel = $config->get('services.stripe.model');

            $this->subscriptionName = $config->get('nova-cashier-manager.subscription_name');

            return $next($request);
        });
    }

    /**
     * Return the user response.
     *
     * @param  int $billableId
     * @param  bool $brief
     * @return \Illuminate\Http\Response
     */
    public function user($billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);


        $subscription = $billable->subscription($this->subscriptionName);



        if (! $subscription) {
            return [
                'subscription' => null,
            ];
        }

//        var_dump($billable->invoices()); die();
        //$stripeSubscription = MollieSubscription::retrieve($subscription->stripe_id);
        $stripeSubscription = $subscription;
        return [
            'user' => $billable->toArray(),
            'cards' => [], //request('brief') ? [] : $this->formatCards($billable->cards(), $billable->defaultCard()->id),
            'invoices' => [], // request('brief') ? [] : $this->formatInvoices($billable->invoices()),
            'charges' => [], //request('brief') ? [] : $this->formatCharges($billable->asStripeCustomer()->charges()),
            'subscription' => $this->formatSubscription($subscription),
            'plans' => request('brief') ? [] : $this->formatPlans( app(PlanRepository::class)::all(['limit' => 100])),
        ];
    }

    /**
     * Cancel the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @return \Illuminate\Http\Response
     */
    public function cancelSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        if ($request->input('now')) {
            $billable->subscription($this->subscriptionName)->cancelNow();
        } else {
            $billable->subscription($this->subscriptionName)->cancel();
        }
    }

    /**
     * Update the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @return \Illuminate\Http\Response
     */
    public function updateSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        $billable->subscription($this->subscriptionName)->swap($request->input('plan'));
    }

    /**
     * Resume the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @param  int $subscriptionId
     * @return \Illuminate\Http\Response
     */
    public function resumeSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        $billable->subscription($this->subscriptionName)->resume();
    }

    /**
     * Refund the given charge.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @param  string $stripeChargeId
     * @return \Illuminate\Http\Response
     */
    public function refundCharge(Request $request, $billableId, $stripeChargeId)
    {
        $refundParameters = ['charge' => $stripeChargeId];

        if ($request->input('amount')) {
            $refundParameters['amount'] = $request->input('amount');
        }

        if ($request->input('notes')) {
            $refundParameters['metadata'] = ['notes' => $request->input('notes')];
        }

        Refund::create($refundParameters);
    }

    /**
     * Format a a subscription object.
     *
     * @param  \Laravel\Cashier\Subscription $subscription
     * @param  \Stripe\Subscription $stripeSubscription
     * @return array
     */
    public function formatSubscription($subscription)
    {


        return array_merge($subscription->toArray(), [
            'plan_amount' => $subscription->plan()->amount(),
            'plan_interval' => $subscription->plan()->interval(),
            'plan_currency' => 'EUR',// $subscription->plan->amount->currency,
            'plan' => $subscription->plan,
            'ended' => $subscription->ended(),
            'cancelled' => $subscription->cancelled(),
            'active' => $subscription->active(),
            'on_trial' => $subscription->onTrial(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'created_at' => $subscription->created_at ? $subscription->created_at->toDateTimeString() : null,
            'ended_at' => null, // $stripeSubscription->ended_at ? Carbon::createFromTimestamp($stripeSubscription->ended_at)->toDateTimeString() : null,
            'current_period_start' => $subscription->cycle_started_at ? $subscription->cycle_started_at->toDateString() : null,
            'current_period_end' => $subscription->cycle_ends_at ? $subscription->cycle_ends_at->toDateString() : null,
            'days_until_due' => null, //$stripeSubscription->days_until_due,
            'cancel_at_period_end' => $subscription->ends_at,
            'canceled_at' => $subscription->ends_at,
        ]);
    }

    /**
     * Format the cards collection.
     *
     * @param  array $cards
     * @param  null|int $defaultCardId
     * @return array
     */
    private function formatCards($cards, $defaultCardId = null)
    {
        return collect($cards)->map(function ($card) use ($defaultCardId) {
            return [
                'id' => $card->id,
                'is_default' => $card->id == $defaultCardId,
                'name' => $card->name,
                'last4' => $card->last4,
                'country' => $card->country,
                'brand' => $card->brand,
                'exp_month' => $card->exp_month,
                'exp_year' => $card->exp_year,
            ];
        })->toArray();
    }

    /**
     * Format the invoices collection.
     *
     * @param  array $invoices
     * @return array
     */
    private function formatInvoices($invoices)
    {
        return collect($invoices)->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'total' => $invoice->total,
                'attempted' => $invoice->attempted,
                'charge_id' => $invoice->charge,
                'currency' => $invoice->currency,
                'period_start' => $invoice->period_start ? Carbon::createFromTimestamp($invoice->period_start)->toDateTimeString() : null,
                'period_end' => $invoice->period_end ? Carbon::createFromTimestamp($invoice->period_end)->toDateTimeString() : null,
            ];
        })->toArray();
    }

    /**
     * Format the charges collection.
     *
     * @param  array $charges
     * @return array
     */
    private function formatCharges($charges)
    {
        return collect($charges->data)->map(function ($charge) {
            return [
                'id' => $charge->id,
                'amount' => $charge->amount,
                'amount_refunded' => $charge->amount_refunded,
                'captured' => $charge->captured,
                'paid' => $charge->paid,
                'status' => $charge->status,
                'currency' => $charge->currency,
                'dispute' => 0, //$charge->dispute ? Dispute::retrieve($charge->dispute) : null,
                'failure_code' => $charge->failure_code,
                'failure_message' => $charge->failure_message,
                'created' => $charge->created ? Carbon::createFromTimestamp($charge->created)->toDateTimeString() : null,
            ];
        })->toArray();
    }

    /**
     * Format the plans collection.
     *
     * @param  array $charges
     * @return array
     */
    private function formatPlans($plans)
    {
        return collect($plans)->map(function ($plan, $key) {

            return [
                'name' => $plan->name(),
                'amount' => $plan->amount(),
                'interval' => $plan->interval(),
                //'currency' => $plan->currency(),
                //'interval_count' => $plan->interval_count(),
            ];
        })->toArray();
    }
}
