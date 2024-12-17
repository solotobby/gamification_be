<?php

namespace App\Repositories;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Wallet;
use App\Models\PaymentTransaction;

class CampaignRepositoryModel
{
    public function __construct() {}

    public function listCategories()
    {
        return Category::orderBy(
            'name',
            'ASC'
        )->get();
    }

    public function listSubCategories($data)
    {
        return SubCategory::where(
            'category_id',
            $data
        )->orderBy(
            'name',
            'DESC'
        )->get();
    }

    public function getSubCategoryAmount($subcategoryId, $categoryId)
    {
        return SubCategory::where('id', $subcategoryId)
            ->where('category_id', $categoryId)
            ->select('amount')
            ->first();
    }
    public function createCampaign($request)
    {
        return Campaign::create($request->all());
    }

    public function getCampaignsByPagination($id)
    {
        return Campaign::where(
            'user_id',
            $id
        )->orderBy(
            'created_at',
            'DESC'
        )->paginate(5);
    }

    public function getCampaignById($id, $userId)
    {
        return Campaign::where(
            'id',
            $id
        )->where(
            'user_id',
            $userId
        )->first();
    }
    public function processPaymentTransaction($user, $campaign, $amount, $currency, $channel, $type, $description)
    {
        $ref = time();

        PaymentTransaction::create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'reference' => $ref,
            'amount' => $amount,
            'status' => 'successful',
            'currency' => $currency,
            'channel' => $channel,
            'type' => 'campaign_posted',
            'description' => $campaign->post_title . ' Campaign'
        ]);
    }

    public function updateAdminWallet($percent, $currency)
    {
        $adminWallet = Wallet::where('user_id', '1')->first();

        if ($currency == 'NGN') {
            $adminWallet->balance += $percent;
        } else {
            $adminWallet->usd_balance += $percent;
        }

        $adminWallet->save();
    }

    public function updateCampaignDetails($campaignId, $numberOfStaff, $totalAmount)
    {
        $campaign = Campaign::where('id', $campaignId)->first();

        $campaign->extension_references = null;
        $campaign->number_of_staff += $numberOfStaff;
        $campaign->total_amount += $totalAmount;
        $campaign->is_completed = false;
        $campaign->save();

        return $campaign;
    }

    public function createPaymentTransaction($userId, $campaignId, $amount)
{
    $ref = time();

    PaymentTransaction::create([
        'user_id' => $userId,
        'campaign_id' => $campaignId,
        'reference' => $ref,
        'amount' => $amount,
        'status' => 'successful',
        'currency' => 'NGN',
        'channel' => 'paystack',
        'type' => 'edit_campaign_payment',
        'description' => 'Extend Campaign Payment'
    ]);
}
    public function logAdminTransaction($amount, $currency, $channel, $user)
    {
        $ref = time();

        PaymentTransaction::create([
            'user_id' => 1,
            'campaign_id' => '1',
            'reference' => $ref,
            'amount' => $amount,
            'status' => 'successful',
            'currency' => $currency,
            'channel' => $channel,
            'type' => 'campaign_revenue',
            'description' => 'Campaign revenue from ' . $user->name,
            'tx_type' => 'Credit',
            'user_type' => 'admin'
        ]);
    }
}
