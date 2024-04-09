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
use App\Models\Referral;
use App\Providers\RouteServiceProvider;

use App\Models\Wallet;
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

    //    //PaystackHelpers::getLocation(); 
       try{
        // return $location = Location::get(request()->ip());

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

        

       return  SystemActivities::activityLog($user, 'account_creation', $user->name .' Registered ', 'regular');
       

        $user->assignRole('regular');
        
        $user->referral_code = Str::random(7);
        $user->save();

        $currency = $curLocation == "Nigeria" ? 'Naira' : 'Dollar';
       
        $wallet =  Wallet::create(['user_id'=> $user->id, 'balance' => '0.00', 'base_currency' => $currency]);

        if($ref_id != ''){
            Referral::create(['user_id' => $user->id, 'referee_id' => $ref_id]);
        }

        $token = $user->createToken('freebyz_api')->accessToken;

       // SystemActivities::activityLog($user, 'account_creation', $user->name .' Registered ', 'regular');
       
        $data['user'] = $user;
        $data['wallet'] = $wallet;
        $data['token'] = $token;

        

       

                     
    //    //return $user = $this->createUser($request);
    //         if($user){
    //             // Auth::login($user);
    //             PaystackHelpers::userLocation('Registeration');
    //             $profile = setProfile($user);//set profile page
                
    //             $token = $user->createToken('freebyz')->accessToken;
                
    //         }

            
           
            
       }catch(Exception $exception){
            return response()->json(['status' => false,  'error'=>$exception->getMessage(), 'message' => 'Error processing request'], 500);
       }

       return response()->json(['status' => true, 'data' => $data,  'message' => 'Registration successfully'], 201);

    }

    public function createUser($request){
        
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
        // $user->base_currency = $location == "Nigeria" ? 'Naira' : 'Dollar';
        $user->save();
        Wallet::create(['user_id'=> $user->id, 'balance' => '0.00']);

        if($ref_id != ''){
            \DB::table('referral')->insert(['user_id' => $user->id, 'referee_id' => $ref_id]);
        }
       

        // $location = PaystackHelpers::getLocation(); //get user location dynamically
        // $wall = Wallet::where('user_id', $user->id)->first();
        // $wall->base_currency = $location == "Nigeria" ? 'Naira' : 'Dollar';
        // $wall->save();

        SystemActivities::activityLog($user, 'account_creation', $user->name .' Registered ', 'regular');

        // if($location == 'Nigeria'){
        //     $phone = '234'.substr($request->phone, 1);
        //     generateVirtualAccountOnboarding($user, $phone);
        // }

        // // $content = 'Your withdrawal request has been granted and your acount credited successfully. Thank you for choosing Freebyz.com';
        // $subject = 'Welcome to Freebyz';
        // Mail::to($request->email)->send(new Welcome($user,  $subject, ''));

        return $user;
    }


    public function login(Request $request){

        $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required',
        ]);

        // $location = PaystackHelpers::getLocation(); //get user location dynamically
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
               
                // setProfile($user);//set profile page 
               
                //set base currency if not set
            //    PaystackHelpers::userLocation('Login');
              
                SystemActivities::activityLog($user, 'login', $user->name .' Logged In', 'regular');
                return response()->json(['status' => true, 'data' => $data,  'message' => 'Login  successful'], 200);

              } else{
                return response()->json(['status' => false, 'message' => 'Incorrect Login or Password'], 401);

              }            
        }

    }

    public function logout(Request $request){

        $request->user()->token()->revoke();
        return response()->json([
            'status' => 'success',
            'message' => 'User is logged out successfully'
            ], 200);

    }
}
