<?php

namespace Kjdion84\Turtle\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Kjdion84\Turtle\Traits\Shellshock;

class AuthController extends Controller
{
    use Shellshock;

    public function __construct()
    {
        $this->middleware('guest')->only(['loginForm', 'login', 'registerForm', 'register', 'passwordEmailForm', 'passwordEmail', 'passwordResetForm', 'passwordReset']);
        $this->middleware('auth')->only(['logout', 'profileForm', 'profile', 'passwordChangeForm', 'passwordChange']);
        $this->middleware('allow:registration')->only(['registerForm', 'register']);
        $this->middleware('GrahamCampbell\Throttle\Http\Middleware\ThrottleMiddleware:5,1')->only('login', 'passwordEmail', 'passwordReset');
    }

    // show login form
    public function loginForm()
    {
        return view('turtle::auth.login');
    }

    // login
    public function login()
    {
        $this->shellshock(request(), [
            'email' => 'required|email',
            'password' => 'required',
        ], true);

        if (auth()->guard()->attempt(request()->only(['email', 'password']), request()->has('remember'))) {
            request()->session()->regenerate();

            activity('Logged In');
            flash('success', 'Logged in!');

            return response()->json(['redirect' => request()->session()->pull('url.intended', route('index'))]);
        }
        else {
            return response()->json(['message' => trans('auth.failed')], 422);
        }
    }

    // logout
    public function logout()
    {
        activity('Logged Out');
        
        auth()->guard()->logout();
        request()->session()->invalidate();

        return redirect()->route('index');
    }

    // show registration form
    public function registerForm()
    {
        return view('turtle::auth.register');
    }

    // register account
    public function register()
    {
        $this->shellshock(request(), [
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed',
            'g-recaptcha-response' => 'sometimes|recaptcha',
        ]);

        // hash password
        request()->merge(['password' => Hash::make(request()->input('password'))]);

        $user = app(config('turtle.models.user'))->create(request()->all());
        event(new Registered($user));
        auth()->guard()->login($user);

        activity('Registered Account');
        flash('success', 'Account registered!');

        return response()->json(['redirect' => route('index')]);
    }

    // show profile edit form
    public function profileForm()
    {
        return view('turtle::auth.profile');
    }

    // edit profile
    public function profile()
    {
        $this->shellshock(request(), [
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . auth()->user()->id,
            'timezone' => 'required|in:' . implode(',', timezone_identifiers_list()),
        ]);

        auth()->user()->update(request()->all());

        activity('Edited Profile');
        flash('success', 'Profile edited!');

        return response()->json(['reload_page' => true]);
    }

    // show password reset link email form
    public function passwordEmailForm()
    {
        return view('turtle::auth.password.email');
    }

    // email password reset link
    public function passwordEmail()
    {
        $this->shellshock(request(), [
            'email' => 'required|email',
            'g-recaptcha-response' => 'sometimes|recaptcha',
        ]);

        if (($user = app(config('turtle.models.user'))->where('email', request()->input('email'))->first())) {
            $token = Password::getRepository()->create($user);

            Mail::send(['text' => 'turtle::emails.password'], ['token' => $token], function (Message $message) use ($user) {
                $message->subject(config('app.name') . ' Password Reset Link');
                $message->to($user->email);
            });

            flash('success', 'Password reset link emailed!');

            return response()->json(['reload_page' => true]);
        }
        else {
            return response()->json(['message' => trans('auth.failed')], 422);
        }
    }

    // show password reset form
    public function passwordResetForm($token)
    {
        return view('turtle::auth.password.reset', compact('token'));
    }

    // reset password
    public function passwordReset()
    {
        $this->shellshock(request(), [
            'email' => 'required|email',
            'password' => 'required|confirmed',
            'g-recaptcha-response' => 'sometimes|recaptcha',
        ]);

        $response = Password::broker()->reset(request()->except('_token'), function ($user, $password) {
            $user->password = Hash::make($password);
            $user->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
            auth()->guard()->login($user);
        });

        if ($response == Password::PASSWORD_RESET) {
            activity('Reset Password');
            flash('success', 'Password reset!');

            return response()->json(['redirect' => route('index')]);
        }
        else {
            return response()->json(['message' => trans($response)], 422);
        }
    }

    // show password change form
    public function passwordChangeForm()
    {
        return view('turtle::auth.password.change');
    }

    // change password
    public function passwordChange()
    {
        $this->shellshock(request(), [
            'current_password' => 'required',
            'password' => 'required|confirmed',
        ]);

        if (Hash::check(request()->input('current_password'), auth()->user()->password)) {
            auth()->user()->update(['password' => Hash::make(request()->input('password'))]);

            activity('Changed Password');
            flash('success', 'Password changed!');

            return response()->json(['reload_page' => true]);
        }
        else {
            return response()->json(['message' => trans('auth.failed')], 422);
        }
    }
}