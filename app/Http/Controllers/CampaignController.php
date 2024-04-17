<?php

namespace App\Http\Controllers;

use App\Helpers\FacebookHelper;
use App\Helpers\PaystackHelpers;
use App\Helpers\Sendmonny;
use App\Helpers\SystemActivities;
use App\Mail\ApproveCampaign;
use App\Mail\CreateCampaign;
use App\Mail\GeneralMail;
use App\Mail\SubmitJob;
use App\Models\Campaign;
use App\Models\CampaignWorker;
use App\Models\Category;
use App\Models\DisputedJobs;
use App\Models\PaymentTransaction;
use App\Models\Rating;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CampaignController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $campaignList = Campaign::where('user_id', auth()->user()->id)->orderBy('created_at', 'DESC')->get();
        return view('user.campaign.index', ['lists' => $campaignList]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('user.campaign.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function show(Campaign $campaign)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $campaign = Campaign::where('job_id', $id)->first();
        return view('user.campaign.edit', ['campaign' => $campaign]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Campaign $campaign)
    {
        $est_amount = $request->number_of_staff * $request->campaign_amount;
        $percent = (50 / 100) * $est_amount;
        $total = $est_amount + $percent;
        //$total = $request->total_amount_pay;

        $wallet = Wallet::where('user_id', auth()->user()->id)->first();

        if($wallet->balance >= $total){
            $wallet->balance -= $total;
            $wallet->save();
            $camp = $camp = Campaign::where('id', $request->post_id)->first();
           
            $camp->extension_references = null;
            $camp->number_of_staff += $request->number_of_staff;
            $camp->total_amount += $total;
            $camp->save();

            $ref = time();

            PaymentTransaction::create([
                'user_id' => auth()->user()->id,
                'campaign_id' => $request->post_id,
                'reference' => $ref,
                'amount' => $total,
                'status' => 'successful',
                'currency' => 'NGN',
                'channel' => 'paystack',
                'type' => 'edit_campaign_payment',
                'description' => 'Extend Campaign Payment'
            ]);
           
            Mail::to(auth()->user()->email)->send(new CreateCampaign($camp));
            return back()->with('success', 'Campaign Updated Successfully');
        }else{
            return back()->with('error', 'You do not have suficient funds in your wallet');
        }

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function destroy(Campaign $campaign)
    {
        //
    }

    public function getCategories()
    {
        return Category::orderBy('name', 'ASC')->get();
    }
    public function getSubCategories($id)
    {
        if(auth()->user()->wallet->base_currency == "Naira"){
            return SubCategory::where('category_id', $id)->orderBy('name', 'DESC')->get();
        }else{
            //  SubCategory::where('category_id', $id)->orderBy('name', 'DESC')->get();
            $subCates = SubCategory::where('category_id', $id)->orderBy('name', 'DESC')->get();//->select(['id', 'amount', 'category_id', 'name', 'usd'])->get();
            $list = [];
            foreach($subCates as $sub){
                $list[] = [ 
                    'id' => $sub->id,
                    'amount' => $sub->usd,
                    'category_id' => $sub->category_id,
                    'name' => $sub->name,
                    'amt_usd' => $sub->amount
                ];
            }
            return $list;
        }
        
    }
    public function getSubcategoriesInfo($id)
    {
        if(auth()->user()->wallet->base_currency == "Naira"){
            return SubCategory::where('id', $id)->first();
        }else{
             $subCates = SubCategory::where('id', $id)->first();

            $list = [
                    'id' => $subCates->id,
                    'amount' => $subCates->usd, 
                    'name' => $subCates->name,
                    'usd' => $subCates->amount
                    ];

            return $list;
        }
    }

    public function postCampaign(Request $request)
    {
        $request->validate([
            'description' => 'required|string',
            'proof' => 'required|string',
            'post_title' => 'required|string',
            'number_of_staff' => 'required',
            'campaign_amount' => 'required'
        ]);

        $est_amount = $request->number_of_staff * $request->campaign_amount;
        $percent = (50 / 100) * $est_amount;
        $total = $est_amount + $percent;
        // [$est_amount, $percent, $total];
        $job_id = rand(10000,10000000);
        // if(walletHandler() == 'sendmonny'){ 
            /////////////////////////sendmonny integration//////////////////////////////////////
            // $balance = Sendmonny::getUserBalance(GetSendmonnyUserId(), accessToken());
            // if($balance >= $total){
            //     $payloadCollection = [
            //         "sender_wallet_id" => GetSendmonnyUserWalletId(), //authenticated User sendmonnywalletid
            //         "sender_user_id" => GetSendmonnyUserId(), //authenticated User sendmonnyuserid
            //         "amount" => $est_amount,
            //         "pin"=> "2222",
            //         "narration" => "Freebyz Campaign",
            //         "islocal" => true,
            //         "reciever_wallet_id" => adminCollection()['wallet_id']
            //     ];

            //     $payloadRevenue = [
            //         "sender_wallet_id" => GetSendmonnyUserWalletId(), //authenticated User sendmonnywalletid
            //         "sender_user_id" => GetSendmonnyUserId(), //authenticated User sendmonnyuserid
            //         "amount" => $percent,
            //         "pin"=> "2222",
            //         "narration" => "Freebyz Campaign",
            //         "islocal" => true,
            //         "reciever_wallet_id" => adminRevenue()['wallet_id']
            //     ];

            //     $collection = Sendmonny::transfer($payloadCollection, accessToken());
            //     // return $ref_coll = $collection['status']['data'];
            //     if($collection['status'] == true){
                    
            //         $revenue = Sendmonny::transfer($payloadRevenue, accessToken());
            //         if($revenue['status'] == true){
            //             // return $ref_rev = $revenue['status']['data'];
            //             $campaign = $this->processCampaign($total,$request,$job_id,$percent);
            //             Mail::to(auth()->user()->email)->send(new CreateCampaign($campaign));
            //             return back()->with('success', 'Campaign Posted Successfully');
            //         }
            //     }else{
            //         return back()->with('error', 'You do not have suficient funds in your wallet');
            //     }

            // } 
        // }else{

            if(auth()->user()->wallet->base_currency == "Naira"){
                
                $walletValidity = checkWalletBalance(auth()->user(), 'Naira', $total);
                if($walletValidity){
                    $debitWallet = debitWallet(auth()->user(), 'Naira', $total);
                    if($debitWallet){
                        $campaign = $this->processCampaign($total,$request,$job_id,$percent);
                        Mail::to(auth()->user()->email)->send(new CreateCampaign($campaign));
                        return back()->with('success', 'Campaign Posted Successfully. A member of our team will activate your campagin in less than 24 hours.');
                    }
                }else{
                    return back()->with('error', 'You do not have suficient funds in your wallet');
                }
                // if(auth()->user()->wallet->balance >= $total){
                //     $wallet = Wallet::where('user_id', auth()->user()->id)->first();
                //     if($wallet->balance >= $total){
                //         $wallet->balance -= $total;
                //         $wallet->save();
                //         $campaign = $this->processCampaign($total,$request,$job_id,$percent);
                //         Mail::to(auth()->user()->email)->send(new CreateCampaign($campaign));
                //         return back()->with('success', 'Campaign Posted Successfully. A member of our team will activate your campagin in less than 24 hours.');
                //     }else{
                //         return back()->with('error', 'You do not have suficient funds in your wallet');
                //     }  
                // }else{
                //     return back()->with('error', 'You do not have suficient funds in your wallet');
                // }
            }else{
                 $walletValidity = checkWalletBalance(auth()->user(), 'Dollar', $total);
                 if($walletValidity){
                        $debitWallet = debitWallet(auth()->user(), 'Dollar', $total);
                        if($debitWallet){
                            $campaign = $this->processCampaign($total,$request,$job_id,$percent);
                            Mail::to(auth()->user()->email)->send(new CreateCampaign($campaign));
                            return back()->with('success', 'Campaign Posted Successfully. A member of our team will activate your campaign in less than 24 hours.');
                        }else{
                            return back()->with('error', 'You do not have suficient funds in your wallet');
                        }
                       
                 }else{
                    return back()->with('error', 'You do not have suficient funds in your wallet');
                 }
                // if(auth()->user()->wallet->usd_balance >= $total){
                //     $wallet = Wallet::where('user_id', auth()->user()->id)->first();
                //     if($wallet->usd_balance >= $total){
                //         $wallet->usd_balance -= $total;
                //         $wallet->save();
                //         $campaign = $this->processCampaign($total,$request,$job_id,$percent);
                //         Mail::to(auth()->user()->email)->send(new CreateCampaign($campaign));
                //         return back()->with('success', 'Campaign Posted Successfully');
                //     }else{
                //         return back()->with('error', 'You do not have suficient funds in your wallet');
                //     }  
                // }else{
                //     return back()->with('error', 'You do not have suficient funds in your wallet');
                // }
            }
            
        // }
    }

    public function processCampaign($total, $request, $job_id, $percent)
    {
        $currency = '';
        $channel = '';
        if(auth()->user()->wallet->base_currency == "Naira"){
            $currency = 'NGN';
            $channel = 'paystack';
        }else{
            $currency = 'USD';
            $channel = 'paypal';
        }
        $request->request->add(['user_id' => auth()->user()->id,'total_amount' => $total, 'job_id' => $job_id, 'currency' => $currency, 'impressions' => 0, 'pending_count' => 0, 'completed_count' => 0]);
        $campaign = Campaign::create($request->all());

        $ref = time();
            PaymentTransaction::create([
                'user_id' => auth()->user()->id,
                'campaign_id' => $campaign->id,
                'reference' => $ref,
                'amount' => $total,
                'status' => 'successful',
                'currency' => $currency,
                'channel' => $channel,
                'type' => 'campaign_posted',
                'description' => $campaign->post_title.' Campaign'
            ]);

            if(auth()->user()->wallet->base_currency == "Naira"){
                $adminWallet = Wallet::where('user_id', '1')->first();
                $adminWallet->balance =+ $percent;
                $adminWallet->save();
            }else{
                $adminWallet = Wallet::where('user_id', '1')->first();
                $adminWallet->usd_balance =+ $percent;
                $adminWallet->save();
            }
            
             //Admin Transaction Tablw
             PaymentTransaction::create([
                'user_id' => 1,
                'campaign_id' => '1',
                'reference' => $ref,
                'amount' => $percent,
                'status' => 'successful',
                'currency' => $currency,
                'channel' => $channel,
                'type' => 'campaign_revenue',
                'description' => 'Campaign revenue from '.auth()->user()->name,
                'tx_type' => 'Credit',
                'user_type' => 'admin'
            ]);
            return $campaign;
    }

    public function viewCampaign($job_id)
    {

        if($job_id == null){
            abort(400);
        }

         $getCampaign = SystemActivities::viewCampaign($job_id);
        
         if($getCampaign->currency == 'USD'){
            if(auth()->user()->USD_verified){
                $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                $checkRating = isset($rating) ? true : false;
                return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
            }else{
                return redirect('conversion');
            }
         }else{

            if(auth()->user()->is_verified){
                if($getCampaign['is_completed'] == true){
                    return redirect('home');
                }else{
                    $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $checkRating = isset($rating) ? true : false;
                    return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
                }
            }elseif(!auth()->user()->is_verified && $getCampaign['campaign_amount'] <= 10){
                if($getCampaign['is_completed'] == true){
                    return redirect('#');
                }else{
                    $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $rating = Rating::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
                    $checkRating = isset($rating) ? true : false;
                    return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
                }
            }else{
                return redirect('info');
            }

         }

        // $getCampaign = Campaign::where('job_id', $job_id)->first();
        // if($getCampaign->campaignType->name == 'Facebook Influencer'){
        //     if(auth()->user()->facebook_id == null){
        //         // return PaystackHelpers::getPosts();
        //         return redirect('auth/facebook');
        //     }
        // }

        // $completed = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $getCampaign->id)->first();
        
        
        // return view('user.campaign.view', ['campaign' => $getCampaign, 'completed' => $completed, 'is_rated' => $checkRating]);
    }

    public function submitWork(Request $request){
       // return $request;
        $this->validate($request, [
            'proof' => 'required|image|mimes:png,jpeg,gif,jpg',
            'comment' => 'required|string',
        ]);

        $check = CampaignWorker::where('user_id', auth()->user()->id)->where('campaign_id', $request->campaign_id)->first();
        if($check){
            return back()->with('error', 'You have comppleted this campaign before');
        }

        // $campaignInfo = Campaign::where('id', $request->campaign_id)->first();
        // $campCount = $campaignInfo->completed()->where('status', '!=', 'Denied')->count();
        
        // if($campCount >= $campaignInfo->number_of_staff){
        //     return back()->with('error', 'This campaign has reach its maximum workers');
        // }

        $campaign = Campaign::where('id', $request->campaign_id)->first();

        $data['campaign'] = $campaign;

        if($request->hasFile('proof')){
         
            $fileBanner = $request->file('proof');
            $Bannername = time() . $fileBanner->getClientOriginalName();
            $filePathBanner = 'proofs/' . $Bannername;
    
            Storage::disk('s3')->put($filePathBanner, file_get_contents($fileBanner), 'public');
            $proofUrl = Storage::disk('s3')->url($filePathBanner);

            $campaignWorker['user_id'] = auth()->user()->id;
            $campaignWorker['campaign_id'] = $request->campaign_id;
            $campaignWorker['comment'] = $request->comment;
            $campaignWorker['amount'] = $request->amount;
            $campaignWorker['proof_url'] = $proofUrl;
            $campaignWorker['currency'] = $campaign->currency;
            $campaignWork = CampaignWorker::create($campaignWorker);
            
            //activity log
            $campaign->pending_count += 1;
            $campaign->save();

            setPendingCount($campaign->id);
            
            $name = SystemActivities::getInitials(auth()->user()->name);
            SystemActivities::activityLog(auth()->user(), 'campaign_submission', $name .' submitted a campaign of NGN'.number_format($request->amount), 'regular');
            
            Mail::to(auth()->user()->email)->send(new SubmitJob($campaignWork)); //send email to the member
        
            $campaign = Campaign::where('id', $request->campaign_id)->first();
            $user = User::where('id', $campaign->user->id)->first();
            $subject = 'Job Submission';
            $content = auth()->user()->name.' submitted a response to the your campaign - '.$campaign->post_title.'. Please login to review.';
            Mail::to($user->email)->send(new GeneralMail($user, $content, $subject, ''));

            return back()->with('success', 'Job Submitted Successfully');
        }else{
            return back()->with('error', 'Upload an image');
        }
    }

    public function mySubmittedCampaign($id)
    {
        $work = CampaignWorker::where('id', $id)->first();
        if(!$work)
        {
            return redirect('home');
        }
        return view('user.campaign.my_submitted_campaign', ['work' => $work]);
    }

    public function activities($id)
    {
        $cam = Campaign::where('job_id', $id)->where('user_id', auth()->user()->id)->first();
        if(!$cam){
            return redirect('home');
        }  
       return view('user.campaign.activities', ['lists' => $cam]);
    }

    public function pauseCampaign($id){
        $campaign = Campaign::where('job_id', $id)->where('user_id', auth()->user()->id)->first();
        if($campaign->status == 'Live'){
            $campaign->status = 'Paused';
            $campaign->save();
        }elseif($campaign->status == 'Decline'){
    
        }else{
            $campaign->status = 'Live';
            $campaign->save();
        }
        return back()->with('success', 'Campaign status updated!');
    }

    public function campaignDecision(Request $request){
        $request->validate([
            'reason' => 'required|string',
        ]);

        $workSubmitted = CampaignWorker::where('id', $request->id)->first();
        $campaign = Campaign::where('id', $workSubmitted->campaign_id)->first();

        if($request->action == 'approve'){

            if($workSubmitted->reason != null){
                return back()->with('error', 'Campaign has been attended to');
           }
          
           $completed_campaign = $campaign->completed()->where('status', 'Approved')->count();
           if($completed_campaign >= $campaign->number_of_staff){
                return back()->with('error', 'Campaign has reached its maximum capacity');
           }

           $user = User::where('id', $workSubmitted->user_id)->first();

           $workSubmitted->status = 'Approved';
           $workSubmitted->reason = $request->reason;
           $workSubmitted->save();

           //update completed action
           $campaign->completed_count += 1;
           $campaign->pending_count -= 1;
           $campaign->save();

           setIsComplete($workSubmitted->campaign_id);

           if($campaign->currency == 'NGN'){
               $currency = 'NGN';
               $channel = 'paystack';
               creditWallet($user, 'Naira', $workSubmitted->amount);
           }elseif($campaign->currency == 'USD'){
               $currency = 'USD';
               $channel = 'paypal';
               creditWallet($user, 'Dollar', $workSubmitted->amount);
            }elseif($campaign->currency == null){
               $currency = 'NGN';
               $channel = 'paystack';
               creditWallet($user, 'Naira', $workSubmitted->amount);
           }


           $ref = time();

           PaymentTransaction::create([
               'user_id' =>  $workSubmitted->user_id,
               'campaign_id' =>  $workSubmitted->campaign->id,
               'reference' => $ref,
               'amount' =>  $workSubmitted->amount,
               'status' => 'successful',
               'currency' => $currency,
               'channel' => $channel,
               'type' => 'campaign_payment',
               'description' => 'Campaign Payment for '. $workSubmitted->campaign->post_title,
               'tx_type' => 'Credit',
               'user_type' => 'regular'
           ]);
           
           SystemActivities::activityLog($user, 'campaign_payment', $user->name .' earned a campaign payment of NGN'.number_format( $workSubmitted->amount), 'regular');
           
           $subject = 'Job Approved';
           $status = 'Approved';
           Mail::to($workSubmitted->user->email)->send(new ApproveCampaign($workSubmitted, $subject, $status));
           return back()->with('success', 'Campaign Approve Successfully');


    
            // if(walletHandler() == 'sendmonny'){ 
            //     $user = User::where('id', $approve->user_id)->first();
            //     $approve->status = 'Approved';
            //     $approve->reason = $request->reason;
            //     $approve->save();
            //   ///sendmonny integration - sending money from freebyz collection account
            //     $payload = [
            //         "sender_wallet_id" => adminCollection()['wallet_id'], //freebyz admin wallet id
            //         "sender_user_id" => adminCollection()['user_id'], //freebyzadmin sendmonny userid
            //         "amount" => $approve->amount,
            //         "pin"=> "2222",
            //         "narration" => "Freebyz Campaign",
            //         "islocal" => true,
            //         "reciever_wallet_id" => userWalletId($approve->user_id)
            //     ];
            
            //     $res = Sendmonny::transfer($payload, accessToken());
            // }else{
                
               

            // }
            

        }else{
           
            //check if the 
            // $chckCount = PaymentTransaction::where('user_id', $workSubmitted->campaign->user_id)->where('type', 'campaign_payment_refund')->whereDate('created_at', Carbon::today())->count();
            // if($chckCount >= 3){
            //     return back()->with('error', 'You cannot deny more than 3 jobs in a day');
            // }
            $workSubmitted->status = 'Denied';
            $workSubmitted->reason = $request->reason;
            $workSubmitted->save();
          
            $this->removePendingCountAfterDenial($workSubmitted->campaign_id);

            // $campaign = Campaign::where('id', $deny->campaign_id)->first();
            $campaingOwner = User::where('id', $campaign->user_id)->first();

            if($campaign->currency == 'NGN'){
                $currency = 'Naira';
                $channel = 'paystack';
            }elseif($campaign->currency == 'USD'){
                $currency = 'Dollar';
                $channel = 'paypal';
            }elseif($campaign->currency == null){
                $currency = 'Naira';
                $channel = 'paystack';
            }

            // creditWallet($campaingOwner, $currency, $workSubmitted->amount);

            // $ref = time();

            // PaymentTransaction::create([
            //     'user_id' => $workSubmitted->campaign->user_id,
            //     'campaign_id' => $workSubmitted->campaign->id,
            //     'reference' => $ref,
            //     'amount' => $workSubmitted->amount,
            //     'status' => 'successful',
            //     'currency' => $currency,
            //     'channel' => $channel,
            //     'type' => 'campaign_payment_refund',
            //     'description' => 'Campaign Payment Refund for '.$workSubmitted->campaign->post_title,
            //     'tx_type' => 'Credit',
            //     'user_type' => 'regular'
            // ]);



            $subject = 'Job Denied';
            $status = 'Denied';
            Mail::to($workSubmitted->user->email)->send(new ApproveCampaign($workSubmitted, $subject, $status));
            return back()->with('success', 'Campaign has been denied');
        }
    }

   
    public function removePendingCountAfterDenial($id){
        $campaign = Campaign::where('id', $id)->first();
        $campaign->pending_count -= 1;
        $campaign->save();
    }

    public function approveCampaign($id)
    {
       $approve = CampaignWorker::where('id', $id)->first();
       if($approve->reason != null){
            return back()->with('error', 'Campaign has been attended to');
       }
            $approve->status = 'Approved';
            $approve->reason = 'Approved by User';
            $approve->save();

            $currency = '';
            $channel = '';
       if($approve->currency == 'NGN'){
            $currency = 'NGN';
            $channel = 'paystack';
            $wallet = Wallet::where('user_id', $approve->user_id)->first();
            $wallet->balance += $approve->amount;
            $wallet->save();
       }else{
            $currency = 'NGN';
            $channel = 'paystack';
            $wallet = Wallet::where('user_id', $approve->user_id)->first();
            $wallet->usd_balance += $approve->amount;
            $wallet->save();
       }
       
       $ref = time();
       PaymentTransaction::create([
            'user_id' => $approve->user_id,
            'campaign_id' => $approve->campaign->id,
            'reference' => $ref,
            'amount' => $approve->amount,
            'status' => 'successful',
            'currency' => $currency,
            'channel' => $channel,
            'type' => 'campaign_payment',
            'description' => 'Campaign Payment for '.$approve->campaign->post_title,
            'tx_type' => 'Credit',
            'user_type' => 'regular'
        ]);

       $subject = 'Job Approved';
       $status = 'Approved';
       Mail::to($approve->user->email)->send(new ApproveCampaign($approve, $subject, $status));

       return back()->with('success', 'Campaign Approve Successfully');

    }

    public function denyCampaign($id)
    {
        $deny = CampaignWorker::where('id', $id)->first();
        $deny->status = 'Denied';
        $deny->reason = 'Denied by User';
        $deny->save();
        $subject = 'Job Denied';
        $status = 'Denied';
        Mail::to($deny->user->email)->send(new ApproveCampaign($deny, $subject, $status));
        return back()->with('error', 'Campaign Denied Successfully');
    }

    public function approvedCampaigns()
    {
        $mycampaigns = Campaign::where('user_id', auth()->user()->id)->pluck('id')->toArray();
        $approved = CampaignWorker::whereIn('campaign_id', $mycampaigns)->where('status', 'Approved')->orderby('created_at', 'ASC')->get();
        return view('user.campaign.approved', ['lists' => $approved]);
    }
    public function deniedCampaigns()
    { 
        $mycampaigns = Campaign::where('user_id', auth()->user()->id)->pluck('id')->toArray();
        $denied = CampaignWorker::whereIn('campaign_id', $mycampaigns)->where('status', 'Denied')->orderby('created_at', 'ASC')->get();
        return view('user.campaign.denied', ['lists' => $denied]);
    }

    public function completedJobs()
    {
        $completedJobs = CampaignWorker::where('user_id', auth()->user()->id)->orderBy('created_at', 'ASC')->get();
        return view('user.campaign.completed_jobs', ['lists' => $completedJobs]);
    }

    public function disputedJobs()
    {
        $disputedJobs = CampaignWorker::where('user_id', auth()->user()->id)->where('is_dispute', true)->orderBy('created_at', 'ASC')->get();
        return view('user.campaign.disputed_jobs', ['lists' => $disputedJobs]);
    }

    public function processDisputedJobs(Request $request){
        $workDone = CampaignWorker::where('id', $request->id)->first();
        $workDone->is_dispute = true;
        $workDone->save();

       $disputedJob = DisputedJobs::create([
            'campaign_worker_id' => $workDone->id,
            'campaign_id' => $workDone->campaign_id,
            'user_id' => auth()->user()->id,
            'reason' => $request->reason
        ]);

        
        if($disputedJob){
            $subject = 'New Dispute Raised';
            $content = 'A despute has been raised by '.auth()->user()->name.' on a Job. Please follow the link below to attend to it.';
            $url = 'admin/campaign/disputes/'.$workDone->id;
            Mail::to('freebyzcom@gmail.com')->send(new GeneralMail(auth()->user(), $content, $subject, $url));
            return back()->with('success', 'Dispute Submitted Successfully');
        }
    }

    public function addMoreWorkers(Request $request){
        $est_amount = $request->new_number * $request->amount;
        $percent = (50 / 100) * $est_amount;
        $total = $est_amount + $percent;
        //[$est_amount, $percent, $total];
        $wallet = Wallet::where('user_id', auth()->user()->id)->first();
        if(auth()->user()->wallet->base_currency == 'Naira'){
            if($wallet->balance >= $total){
                $wallet->balance -= $total;
                $wallet->save();
                
                $campaign = Campaign::where('job_id', $request->id)->first();
                $campaign->number_of_staff += $request->new_number;
                $campaign->total_amount += $est_amount;
                $campaign->is_completed = false;
                $campaign->save();

                $currency = 'NGN';
                $channel = 'paystack';

                $ref = time();
                    PaymentTransaction::create([
                        'user_id' => auth()->user()->id,
                        'campaign_id' => $campaign->id,
                        'reference' => $ref,
                        'amount' => $total,
                        'status' => 'successful',
                        'currency' => $currency,
                        'channel' => $channel,
                        'type' => 'added_more_worker',
                        'description' => 'Added worker for '.$campaign->post_title.' campaign',
                        'tx_type' => 'Debit',
                        'user_type' => 'regular'
                    ]);

                    //credit admin 
                    $adminWallet = Wallet::where('user_id', '1')->first();
                    $adminWallet->balance += $percent;
                    $adminWallet->save();
                    PaymentTransaction::create([
                        'user_id' => '1',
                        'campaign_id' => $campaign->id,
                        'reference' => $ref,
                        'amount' => $percent,
                        'status' => 'successful',
                        'currency' => $currency,
                        'channel' => $channel,
                        'type' => 'campaign_revenue_add',
                        'description' => 'Revenue for worker added on '.$campaign->post_title.' campaign',
                        'tx_type' => 'Credit',
                        'user_type' => 'admin'
                    ]);

                $content = "You have successfully increased the number of your workers.";
                $subject = "Add More Worker";
                $user = User::where('id', auth()->user()->id)->first();
                Mail::to(auth()->user()->email)->send(new GeneralMail($user, $content, $subject, ''));
                return back()->with('success', 'Worker Updated Successfully');
            }else{
                return back()->with('error', 'You do not have suficient funds in your wallet');
            }
        }else{
            if($wallet->usd_balance >= $total){
                $wallet->usd_balance -= $total;
                $wallet->save();
                
                $campaign = Campaign::where('job_id', $request->id)->first();
                $campaign->number_of_staff += $request->new_number;
                $campaign->total_amount += $est_amount;
                $campaign->is_completed = false;
                $campaign->save();


                $currency = 'USD';
                $channel = 'paypal';

                $ref = time();
                    PaymentTransaction::create([
                        'user_id' => auth()->user()->id,
                        'campaign_id' => $campaign->id,
                        'reference' => $ref,
                        'amount' => $total,
                        'status' => 'successful',
                        'currency' => $currency,
                        'channel' => $channel,
                        'type' => 'added_more_worker',
                        'description' => 'Added worker for '.$campaign->post_title.' campaign',
                        'tx_type' => 'Debit',
                        'user_type' => 'regular'
                    ]);

                    //credit admin 
                    $adminWallet = Wallet::where('user_id', '1')->first();
                    $adminWallet->usd_balance += $percent;
                    $adminWallet->save();

                    PaymentTransaction::create([
                        'user_id' => '1',
                        'campaign_id' => $campaign->id,
                        'reference' => $ref,
                        'amount' => $percent,
                        'status' => 'successful',
                        'currency' => $currency,
                        'channel' => $channel,
                        'type' => 'campaign_revenue_add',
                        'description' => 'Revenue for worker added on '.$campaign->post_title.' campaign',
                        'tx_type' => 'Credit',
                        'user_type' => 'admin'
                    ]);


                $content = "You have successfully increased the number of your workers.";
                $subject = "Add More Worker";
                $user = User::where('id', auth()->user()->id)->first();
                Mail::to(auth()->user()->email)->send(new GeneralMail($user, $content, $subject, ''));
                return back()->with('success', 'Worker Updated Successfully');
            }else{
                return back()->with('error', 'You do not have suficient funds in your wallet');
            }

        }
           
    } 
    
    public function adminActivities($id){

        $cam = Campaign::where('job_id', $id)->first();
            
        $approved = $cam->completed()->where('status', 'Approved')->count();

        $remainingNumber = $cam->number_of_staff - $approved;

        $count =  $remainingNumber;

        return view('admin.campaign_mgt.admin_activities', ['lists' => $cam, 'count' => $count]);

    }
}
