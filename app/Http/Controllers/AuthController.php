<?php

namespace App\Http\Controllers;

use App\Models\User;
use Exception;
use Illuminate\Http\Request;

use App\Helpers\PaystackHelpers;
use App\Helpers\Sendmonny;
use App\Helpers\SystemActivities;
use App\Http\Controllers\Controller;
use App\Jobs\SendMassEmail;
use App\Mail\GeneralMail;
use App\Mail\Welcome;
use App\Models\AccountInformation;
use App\Models\ActivityLog;
use App\Models\OTP;
use App\Models\Referral;
use App\Providers\RouteServiceProvider;

use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\RegistersUsers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Stevebauman\Location\Facades\Location;

class AuthController extends Controller
{
    public function register(Request $request){
       $curLocation = currentLocation();
       if($curLocation == 'Nigeria'){
       
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'numeric', 'digits:11', 'unique:users'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
       }else{
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'numeric', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'unique:users'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
       }

  
       try{
        // return $location = Location::get(request()->ip());

        $res = $this->createUser($request, $curLocation);

        if($res){

            $user = $res['user'];
            $wallet = $res['wallet'];
            $profile = setProfile($user);

            $token = $user->createToken('freebyz_api')->accessToken;
           
            $data['user'] = $user;
            $data['profile'] = $profile;
            $data['wallet'] = $wallet;
            $data['token'] = $token;
    
        }
       
       }catch(Exception $exception){
            return response()->json(['status' => false,  'error'=>$exception->getMessage(), 'message' => 'Error processing request'], 500);
       }

       return response()->json(['message' => 'Registration successfully', 'status' => true, 'data' => $data], 201);

    }

    public function createUser($request, $curLocation){
        
        $ref_id = $request->ref_id;
        $name = $request->first_name.' '.$request->last_name;
        
        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'country' => $request->country,
            'phone' => $request->phone,
            'source' => $request->source,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('regular');
        
        $user->referral_code = Str::random(7);
        $user->save();

        $currency = $curLocation == "Nigeria" ? 'Naira' : 'Dollar';
       
        $wallet =  Wallet::create(['user_id'=> $user->id, 'balance' => '0.00', 'base_currency' => $currency]);

        if($ref_id != ''){
            Referral::create(['user_id' => $user->id, 'referee_id' => $ref_id]);
        }
        if($curLocation == 'Nigeria'){
            $phone = '234'.substr($request->phone, 1);
            generateVirtualAccountOnboarding($user, $phone);
        }
        activityLog($user, 'account_creation', $user->name .' Registered ', 'regular');

        //process email verification code
        $startTime = date("Y-m-d H:i:s");
        $convertedTime = date('Y-m-d H:i:s', strtotime('+2 minutes', strtotime($startTime)));

        $code = random_int(100000, 999999);

        OTP::create(['user_id' => $user->id, 'pinId' => $convertedTime, 'phone_number' => '1234567890', 'otp' => $code, 'is_verified' => false]);
        $subject = 'Freebyz Email Verirification';

        $content = 'Hi,'.$user->name.' Your Email Verification Code is '.$code;
        Mail::to($user->email)->send(new GeneralMail($user, $content, $subject, ''));



        // $content = 'Your withdrawal request has been granted and your acount credited successfully. Thank you for choosing Freebyz.com';
        $subject = 'Welcome to Freebyz';
        Mail::to($request->email)->send(new Welcome($user,  $subject, ''));

        $data['user'] = $user;
        $data['wallet'] = $wallet;
        return $data;
    }


    public function login(Request $request){

        $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required',
        ]);

       
        $user = User::where('email', $request->email)->first();
        $role = $user->getRoleNames();
        if($role == []){
            $user->assignRole('regular');
           // 
        }

        if($user){
            if($user->referral_code == null){
                $user->referral_code = Str::random(7);
                $user->save();
             }

             if(Hash::check($request->password, $user->password)){
                $data['user'] = User::with(['roles'])->where('email', $request->email)->first();
                $data['token'] = $user->createToken('freebyz')->accessToken;
               
                setProfile($user);//set profile page 
               
                activityLog($user, 'login', $user->name .' Logged In', 'regular');

                return response()->json(['message' => 'Login  successful', 'status' => true, 'data' => $data], 200);

              } else{
                return response()->json(['status' => false, 'message' => 'Incorrect Login or Password'], 401);

              }            
        }

    }

    public function sendEmailOTP(Request $request){
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        try{
            
            $user = User::where('email', $request->email)->first();
            
            if($user){

                $startTime = date("Y-m-d H:i:s");
                $convertedTime = date('Y-m-d H:i:s', strtotime('+5 minutes', strtotime($startTime)));

                $code = random_int(100000, 999999);

                OTP::create(['user_id' => $user->id, 'pinId' => $convertedTime, 'phone_number' => '1234567890', 'otp' => $code, 'is_verified' => false]);
                $subject = 'Freebyz Email Verirification';

                $content = 'Hi,'.$user->name.' Your Email Verification Code is '.$code;
                Mail::to($user->email)->send(new GeneralMail($user, $content, $subject, ''));

            }else{
                return response()->json(['status' => false, 'message' => 'User cannot be found'], 401);
            }
            
        }catch(Exception $exception){
            return response()->json(['status' => false,  'error'=>$exception->getMessage(), 'message' => 'Error processing request'], 500);
        }
        return response()->json(['status' => true, 'message' => 'Email Verification code sent'], 200);

    }

    public function validateOTP(Request $request){
        $request->validate([
            'otp' => 'required|numeric|digits:6',
        ]);

        try{

            $otp = OTP::where('otp', $request->otp)->first();
            if($otp){
                $startTime = date($otp->created_at);
                $convertedTime = date('Y-m-d H:i:s', strtotime('+1 minutes', strtotime($startTime)));
                $curdateTime = date('Y-m-d H:i:s');
                if($curdateTime > $otp->pinId){
                    $otp->delete();
                    return response()->json(['status' => false, 'message' => 'Otp expired, please request another one'], 401);
                }
                // return [$startTime, $convertedTime, $curdateTime];

            }else{
                return response()->json(['status' => false, 'message' => 'Otp is not correct, please request another one'], 401);
            }

            $user = User::with(['roles'])->where('id', $otp->user_id)->first();

            $otp->delete();
            
            
        }catch(Exception $exception){
            return response()->json(['status' => false,  'error'=>$exception->getMessage(), 'message' => 'Error processing request'], 500);
        }
        return response()->json(['status' => true, 'message' => 'Email Verified successfully', 'data' => $user], 200);


    }

    public function logout(Request $request){

        $request->user()->token()->revoke();
        return response()->json([
            'status' => 'success',
            'message' => 'User is logged out successfully'
            ], 200);

    }
}
