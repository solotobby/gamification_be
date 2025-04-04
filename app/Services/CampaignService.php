<?php

namespace App\Services;

use App\Repositories\CampaignRepositoryModel;
use App\Repositories\WalletRepositoryModel;
use App\Repositories\Admin\CurrencyRepositoryModel;
use Throwable;
use Illuminate\Support\Facades\Mail;
use App\Validators\CampaignValidator;
use App\Helpers\SystemActivities;
use App\Mail\CreateCampaign;
use App\Models\Campaign;
use App\Models\CampaignWorker;
use App\Models\Rating;
use App\Repositories\AuthRepositoryModel;
use App\Repositories\JobRepositoryModel;
use Exception;

class CampaignService
{
    protected $jobModel;
    protected $currencyModel;
    protected $walletModel;
    protected $authModel;
    protected $campaignModel;
    protected $validator;
    public function __construct(
        CampaignRepositoryModel $campaignModel,
        CampaignValidator $validator,
        CurrencyRepositoryModel $currencyModel,
        WalletRepositoryModel $walletModel,
        AuthRepositoryModel $authModel,
        JobRepositoryModel $jobModel
    ) {
        $this->campaignModel = $campaignModel;
        $this->validator = $validator;
        $this->currencyModel = $currencyModel;
        $this->walletModel = $walletModel;
        $this->authModel = $authModel;
        $this->jobModel = $jobModel;
    }

    public function getCampaigns($request)
    {
        try {
            $user = auth()->user();

            $type = strtolower($request->query('type'));
            // Fetch campaigns by user ID
            $campaigns = $this->campaignModel->getCampaignsByPagination($user->id, $type);

            // Fetch user's base currency and map it
            $baseCurrency = $user->wallet->base_currency;
            $mapCurrency = $this->walletModel->mapCurrency($baseCurrency);

            // Fetch currency details
            $currency = $this->currencyModel->getCurrencyByCode($mapCurrency);

            // Validate retrieved data
            if (!$currency) {
                return response()->json([
                    'status' => false,
                    'message' => 'Currency not found.'
                ], 404);
            }

            // Prepare campaign data
            $data = [];
            foreach ($campaigns as $campaign) {
                $unitPrice = $campaign->campaign_amount;
                $totalAmount = $campaign->total_amount;

                // Check if conversion is needed
                if ($currency->code !== $campaign->currency) {
                    $rate = $this->currencyConversion($campaign->currency, $currency->code);

                    $unitPrice *= $rate;
                    $totalAmount *= $rate;
                }

                $data[] = [
                    'id' => $campaign->id,
                    'user_id' => $campaign->user_id,
                    'campaign_id' => $campaign->job_id,
                    'title' => $campaign->post_title,
                    'approved' => $campaign->completed_count . '/' . $campaign->number_of_staff,
                    'unit_price' => round($unitPrice, 5),
                    'total_amount' => round($totalAmount, 5),
                    'currency' => $currency->code,
                    'status' => $campaign->status,
                    'created' => $campaign->created_at,
                ];
            }
            $pagination = [
                'total' => $campaigns->total(),
                'per_page' => $campaigns->perPage(),
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'from' => $campaigns->firstItem(),
                'to' => $campaigns->lastItem(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Campaign List',
                'data' => $data,
                'pagination' => $pagination,
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }

    public function currencyConversion($from, $to)
    {
        $currencyRate = $this->currencyModel->convertCurrency($from, $to);

        // return $currencyRate;
        if (!$currencyRate) {
            return response()->json([
                'status' => false,
                'message' => 'Currency conversion rate not found.'
            ], 404);
        }

        $rate = $currencyRate->rate;
        return $rate;
    }
    public function create($request)
    {
        $this->validator->validateCampaignCreation($request);

        try {
            $user = auth()->user();
            $baseCurrency = $user->wallet->base_currency;

            // Map currency to determine prioritization and upload amount
            $mapCurrency = $this->walletModel->mapCurrency($baseCurrency);

            // Get the currency details and status
            $currency = $this->currencyModel->getCurrencyByCode($mapCurrency);

            // Determine prioritization amount and status
            $prAmount = $request->priotize ? (float)($currency->priotize) : 0;
            $priotize = $request->priotize ? 'Priotize' : 'Pending';

            // Calculate initial upload amount
            $iniAmount = $request->allow_upload ? $request->number_of_staff * (float)($currency->allow_upload) : 0;
            $allowUpload = (bool)$request->allow_upload;

            // Get the Subcategory amount from db
            $subAmount = $this->campaignModel->getSubCategoryAmount(
                $request->campaign_subcategory,
                $request->campaign_type
            );
            // return $subAmount;
            // Calculate estimated amount and total
            $estAmount = $request->number_of_staff * $subAmount->amount;
            $percent = (60 / 100) * $estAmount;
            $total = $estAmount + $percent + $iniAmount + $prAmount;

            // Generate a unique job ID
            $jobId = rand(10000, 10000000);

            // Check wallet balance and debit if valid
            if (!$this->walletModel->checkWalletBalance(
                $user,
                $currency->code,
                $total
            )) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have sufficient funds in your wallet',
                ], 401);
            }

            if (!$this->walletModel->debitWallet(
                $user,
                $currency->code,
                $total
            )) {
                return response()->json([
                    'status' => false,
                    'message' => 'Wallet debit failed. Please try again.',
                ], 401);
            }

            // Process the campaign
            $campaign = $this->processCampaign(
                $total,
                $request,
                $jobId,
                $percent,
                $allowUpload,
                $priotize,
                $currency,
            );

            // Notify user via email
            Mail::to($user->email)->send(new CreateCampaign($campaign));

            return response()->json([
                'status' => true,
                'message' => 'Campaign Posted Successfully. A member of our team will activate your campaign within 24 hours.',
                'data' => $campaign,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
                'message' => 'Error processing request',
            ], 500);
        }
    }


    public function updateCampaignWorker($request)
    {

        $this->validator->validateCampaignUpdating($request);
        try {

            $user = auth()->user();
            // Get the campaign details using the UserId and CampaignId
            $campaign = $this->campaignModel->getCampaignByJobId($request->campaign_id, $user->id);

            $baseCurrency = $user->wallet->base_currency;

            // Map currency to determine upload amount
            $mapCurrency = $this->walletModel->mapCurrency($baseCurrency);

            // Get the currency details and status
            $currency = $this->currencyModel->getCurrencyByCode($mapCurrency);

            // Calculate initial upload amount
            $iniAmount = $campaign->allow_upload ? $request->new_worker_number * (float)($currency->allow_upload) : 0;

            // Get the Subcategory amount from db
            $subAmount = $this->campaignModel->getSubCategoryAmount(
                $campaign->campaign_subcategory,
                $campaign->campaign_type
            );
            $estAmount = $request->new_worker_number * $subAmount->amount;
            $percent = (60 / 100) * $estAmount;
            $total = $estAmount + $percent + $iniAmount;

            // Check wallet balance and debit if valid
            if (!$this->walletModel->checkWalletBalance($user, $baseCurrency, $total)) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have sufficient funds in your wallet',
                ], 401);
            }

            if (!$this->walletModel->debitWallet($user, $baseCurrency, $total)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Wallet debit failed. Please try again.',
                ], 500);
            }

            // Process the campaign
            $saveCampaign = $this->campaignModel->updateCampaignDetails(
                $campaign->id,
                $request->new_worker_number,
                $total
            );
            // Create transaction
            $this->campaignModel->createPaymentTransaction(
                $user->id,
                $campaign->id,
                $total
            );

            $saveCampaign['campaign_id'] = $campaign->job_id;
            // Notify user via email
            Mail::to($user->email)->send(new CreateCampaign($saveCampaign));

            return response()->json([
                'status' => true,
                'message' => 'Campaign Updated Successfully',
                'data' => $saveCampaign,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
                'message' => 'Error processing request',
            ], 500);
        }
    }

    public function getCategories()
    {
        try {
            $baseCurrency = auth()->user()->wallet->base_currency;
            $mapCurrency = $this->walletModel->mapCurrency($baseCurrency);

            // Fetch all active categories
            $categories = $this->campaignModel->listCategories();
            if (!$categories) {
                return response()->json([
                    'status' => false,
                    'message' => 'No categories found',
                    'data' => []
                ], 404);
            }

            $data['category'] = $categories->map(function ($category) use ($mapCurrency) {
                // Fetch subcategories for this category
                $subCategories = $this->campaignModel->listSubCategories($category['id']);

                // Transform the subcategories
                $subCategoryData = $subCategories->map(function ($sub) use ($mapCurrency) {
                    $amount = $sub->amount;

                    // If the currency is not NGN, convert the amount
                    if ($mapCurrency !== 'NGN') {
                        $currencyRate = $this->currencyModel->convertCurrency('NGN', $mapCurrency);

                        if (!$currencyRate) {
                            return response()->json(['status' => false, 'message' => 'Currency conversion rate not found.'], 404);
                        }

                        $rate = $currencyRate->rate;
                        $amount *= $rate; // Convert the amount based on the rate
                    }

                    return [
                        'id' => $sub->id,
                        'amount' => round($amount, 5),
                        'category_id' => $sub->category_id,
                        'name' => $sub->name,
                    ];
                });

                // Add subcategories under the category
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'subcategories' => $subCategoryData
                ];
            });

            // Get the currency details
            $data['currency']  = $this->currencyModel->getCurrencyByCode($mapCurrency);


            return response()->json([
                'status' => true,
                'message' => 'Categories fetched successfully',
                'data' => $data
            ], 200);
        } catch (Throwable $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage(), 'message' => 'Error processing request'], 500);
        }
    }

    public function campaignActivitiesStat($campaignId)
    {
        try {
            $userId = auth()->user()->id;
            $campaign = $this->campaignModel->getCampaignByJobId($campaignId, $userId);

            // Return error if campaign is not found
            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            // Prepare Stat Response
            $data['campaign_id'] = $campaign->job_id;
            $data['number_of_workers'] = $campaign->number_of_staff;
            $data['spent_amount'] = $spentAmount = $this->jobModel->getCampaignSpentAmount($campaign->id);
            $data['campaign_total_amount'] = $campaignAmount = $campaign->campaign_amount * $campaign->number_of_staff;
            $data['campaign_unit_amount'] = $campaign->campaign_amount;
            $data['campaign_currency'] = $campaign->currency;
            $data['amount_ratio'] = $campaign->currency . '' . $spentAmount . ' / ' . $campaign->currency . '' . $campaignAmount;
            $data['status'] = $this->jobModel->getCampaignStats($campaign->id);

            return response()->json([
                'status' => true,
                'message' => 'Campaign Activities Stat',
                'data' => $data
            ], 200);
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }

    public function campaignJobList($request, $campaignId)
    {
        try {
            $userId = auth()->user()->id;
            $type = strtolower($request->query('type'));
            $page = strtolower($request->query('page'));

            $campaign = $this->campaignModel->getCampaignByJobId($campaignId, $userId);

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            $jobs = $this->jobModel->getJobsByIdAndType($campaign->id, $type, $page);

            $data['campaign_name'] = $campaign->post_title;
            $data['campaign_id'] = $campaign->job_id;
            $data['jobs'] = $jobs->getCollection()->map(function ($job) use ($campaign) {
                return [
                    'job_id' => $job->id,
                    'worker_name' => $this->authModel->findUserById($job->user_id)->name ?? 'Unknown',
                    'campaign_name' => $campaign->post_title,
                    'amount' => $campaign->currency . ' ' . $job->amount,
                    'status' => $job->status,
                    'created_at' => $job->created_at,
                    'updated_at' => $job->updated_at,

                ];
            });

            $pagination = [
                'total' => $jobs->total(),
                'per_page' => $jobs->perPage(),
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'from' => $jobs->firstItem(),
                'to' => $jobs->lastItem(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Campaign Activities Jobs',
                'data' => $data,
                'pagination' => $pagination,
            ], 200);
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }

    public function allCampaignJobList($request)
    {
        try {
            $userId = auth()->user()->id;
            $type = strtolower($request->query('type'));
            $page = $request->query('page', 1);

            $campaigns = $this->campaignModel->getUserCampaigns($userId);
            if ($campaigns->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No campaigns found for the user.'
                ], 404);
            }

            $campaignIds = $campaigns->pluck('id')->toArray();

            // Fetch jobs performed on user's campaigns
            $jobs = $this->jobModel->getJobsByCampaignIdsAndType($campaignIds, $type, $page);

            if ($jobs->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No jobs found for the user campaigns.'
                ], 404);
            }

            // Format job data
            $data = $jobs->getCollection()->map(function ($job) {
                $worker = $this->authModel->findUserById($job->user_id);

                return [
                    'campaign_id' => $job->campaign->job_id,
                    'job_id' => $job->id,
                    'worker_name' => $worker->name ?? 'Unknown',
                    'campaign_name' => $job->campaign->post_title,
                    'amount' => "{$job->campaign->currency} {$job->amount}",
                    'status' => $job->status,
                    'created_at' => $job->created_at,
                    'updated_at' => $job->updated_at,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => ucfirst($type) . ' Campaign Jobs Retrieved Successfully',
                'data' => $data,
                'pagination' => [
                    'total' => $jobs->total(),
                    'per_page' => $jobs->perPage(),
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'from' => $jobs->firstItem(),
                    'to' => $jobs->lastItem(),
                ],
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }


    public function pauseCampaign($campaignId)
    {
        try {
            $userId = auth()->user()->id;
            // Retrieve the campaign for the authenticated user
            $campaign = $this->campaignModel->getCampaignByJobId($campaignId, $userId);

            // Return error if campaign is not found
            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            // Toggle the campaign status
            if ($campaign->status === 'Live') {
                $campaign->status = 'Paused';
            } elseif ($campaign->status === 'Paused') {
                $campaign->status = 'Live';
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign status cannot be paused from its current state'
                ], 400);
            }

            // Save the updated campaign status
            $campaign->save();

            $campaign['campaign_id'] = $campaign->job_id;
            return response()->json([
                'status' => true,
                'message' => 'Campaign status updated successfully to ' . $campaign->status,
                'data' => $campaign
            ], 200);
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }

    public function jobDetails($request)
    {

        try {
            $userId = auth()->user()->id;

            $campId = $request->query('campaign_id');
            $jobId = $request->query('job_id');

            if (empty($campId) || empty($jobId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign Id and Job Id cannot be empty'
                ], 400);
            }

            $campaign = $this->campaignModel->getCampaignByJobId($campId, $userId);

            // Return error if campaign is not found
            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $job = $this->jobModel->getJobByIdAndCampaignId($jobId, $campaign->id);
            // return $job;
            $data = [
                'job_id' => $job->id,
                'campaign_id' => $campaign->job_id,
                'campaign_name' => $campaign->post_title,
                'campaign_description' => $campaign->description,
                'proof_of_completion' => $campaign->proof,
                'worker_name' => $this->authModel->findUserById($job->user_id)->name,
                'worker_id' => $job->user_id,
                'worker_proof' => $job->comment,
                'worker_proof_url' => $job->proof_url,
                'job_status' => $job->status,
                'approval_or_denial_reason' => $job->reason,
                'created_at' => $job->created_at,
                'updated_at' => $job->updated_at,
                'has_dispute' => $job->is_dispute,
                'dispute_resolved' => $job->is_dispute_resolved,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Job details retrieved successfully',
                'data' => $data
            ], 200);
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
    }

    public function decreasePendingCountAfterDenial($id)
    {
        $userId = auth()->user()->id;
        $campaign = $this->campaignModel->getCampaignById($id, $userId);
        $campaign->pending_count -= 1;
        $campaign->save();
        return true;
    }

    public function increaseCompletedCountAfterApproval($id)
    {
        $userId = auth()->user()->id;
        $campaign = $this->campaignModel->getCampaignById($id, $userId);
        $campaign->completed_count += 1;
        $campaign->save();
        return true;
    }

    private function processCampaign($total, $request, $job_id, $percent, $allowUpload, $priotize, $currency)
    {
        $user = auth()->user();
        $channel = $currency->code == "NGN" ? 'paystack' : 'paypal';

        $request->merge([
            'user_id' => $user->id,
            'total_amount' => $total,
            'job_id' => $job_id,
            'currency' => $currency->code,
            'impressions' => 0,
            'pending_count' => 0,
            'completed_count' => 0,
            'allow_upload' => $allowUpload,
            'approved' => $priotize
        ]);

        // Create the campaign
        $campaign = $this->campaignModel->createCampaign($request);

        // Process payment transaction
        $this->campaignModel->processPaymentTransaction(
            $user,
            $campaign,
            $total,
            $currency->code,
            $channel,
        );

        // Update admin wallet
        $this->campaignModel->updateAdminWallet($percent, $currency->code);

        // Log admin transaction
        $this->campaignModel->logAdminTransaction($percent, $currency->code, $channel, $user);

        return $campaign;
    }
    public function approveOrDeclineJob($request)
    {
        $this->validator->approveOrDenyReason($request);

        try {
            $user = auth()->user();
            $action = strtolower($request->action);
            $jobId = $request->job_id;
            $campId = $request->campaign_id;
            $reason = $request->reason;

            $campaign = $this->campaignModel->getCampaignByJobId($campId);
            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found.',
                ], 404);
            }
            // Retrieve job details
            $job = $this->jobModel->getJobByIdAndCampaignId($jobId, $campaign->id);
            if (!$job) {
                return response()->json([
                    'status' => false,
                    'message' => 'Job not found.',
                ], 404);
            }

            // Prevent duplicate actions on already processed jobs
            if (in_array($job->status, ['Approved', 'Denied'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'This job has already been ' . strtolower($job->status) . '. Action cannot be performed.',
                ], 400);
            }

            // Retrieve worker details
            $worker = $this->authModel->findUserById($job->user_id);
            $currency = $worker->wallet->base_currency;

            // Perform action
            if ($action === 'deny') {
                $job = $this->jobModel->updateJobStatus($reason, $jobId, 'Denied');
                $this->decreasePendingCountAfterDenial($campaign->id);
            } elseif ($action === 'approve') {
                $job = $this->jobModel->updateJobStatus($reason, $jobId, 'Approved');
                $this->increaseCompletedCountAfterApproval($campaign->id);
                $this->walletModel->creditWallet($worker, $currency, $job->amount);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid action. Only "approve" or "deny" are allowed.',
                ], 400);
            }

            // Prepare response data
            $data = [
                'job_id' => $job->id,
                'campaign_id' => $campaign->job_id,
                'campaign_name' => $campaign->post_title,
                'campaign_description' => $campaign->description,
                'proof_of_completion' => $campaign->proof,
                'worker_name' => $worker->name,
                'worker_id' => $job->user_id,
                'worker_proof' => $job->comment,
                'worker_proof_url' => $job->proof_url,
                'job_status' => $job->status,
                'approval_or_denial_reason' => $job->reason,
                'created_at' => $job->created_at,
                'updated_at' => $job->updated_at,
                'has_dispute' => (bool) $job->is_dispute,
                'dispute_resolved' => (bool) $job->is_dispute_resolved,
            ];

            return response()->json([
                'status' => true,
                'message' => ucfirst($action) . ' action completed successfully.',
                'job' => $data,
            ], 200);
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request.',
            ], 500);
        }
    }




    public function viewCampaign($job_id)
    {
        try {
            $getCampaign = SystemActivities::viewCampaign($job_id);

            if ($getCampaign->currency == 'USD') {
                if (auth()->user()->USD_verified) {
                    $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $checkRating = isset($rating) ? true : false;

                    $data['campaign'] = $getCampaign;
                    $data['completed'] = $completed;
                    $data['is_rated'] = $checkRating;
                    // return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
                } else {
                    return response()->json(['status' => false, 'message' => 'User not verified for Dollar, redirect to a page to get verified'], 401);
                    // return redirect('conversion');
                }
            } else {

                if (auth()->user()->is_verified) {
                    if ($getCampaign['is_completed'] == true) {
                        return redirect('home');
                    } else {
                        $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                        $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                        $checkRating = isset($rating) ? true : false;
                        $data['campaign'] = $getCampaign;
                        $data['completed'] = $completed;
                        $data['is_rated'] = $checkRating;
                        // return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
                    }
                } elseif (!auth()->user()->is_verified && $getCampaign['campaign_amount'] <= 10) {
                    if ($getCampaign['is_completed'] == true) {
                        return response()->json(['status' => false, 'message' => 'The campaign is completed'], 401);
                        // return redirect('#');

                    } else {
                        $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                        $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                        $checkRating = isset($rating) ? true : false;
                        $data['campaign'] = $getCampaign;
                        $data['completed'] = $completed;
                        $data['is_rated'] = $checkRating;
                        // return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
                    }
                } else {
                    return response()->json(['status' => false, 'message' => 'User not verified for Naira, redirect to a page to get verified'], 401);
                    // return redirect('info'); // show user they are not verified, a button should
                }
            }
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'error' => $exception->getMessage(),
                'message' => 'Error processing request'
            ], 500);
        }
        return response()->json([
            'status' => true,
            'message' => 'Campaign Information',
            'data' => $data
        ], 200);
    }


    public function adminActivities($id)
    {

        $cam = Campaign::where('job_id', $id)->first();

        $approved = $cam->completed()->where('status', 'Approved')->count();

        $remainingNumber = $cam->number_of_staff - $approved;

        $count =  $remainingNumber;

        return view('admin.campaign_mgt.admin_activities', ['lists' => $cam, 'count' => $count]);
    }
}
