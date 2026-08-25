<?php

namespace App\Http\Controllers;

use App\Actions\AuthenticateSilpoUserAction;
use App\Services\SilpoOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class SilpoOAuthController extends Controller
{
    public function redirect(SilpoOAuthClient $oauth): RedirectResponse
    {
        try {
            return $oauth->redirect();
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()->route('landing')
                ->with('error', 'Не вдалося розпочати вхід через Сільпо. Спробуйте ще раз.');
        }
    }

    public function callback(
        Request $request,
        SilpoOAuthClient $oauth,
        AuthenticateSilpoUserAction $authenticate,
    ): RedirectResponse {
        try {
            $token = $oauth->exchangeCallback();
            $user = $authenticate->execute($token);
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()->route('landing')
                ->with('error', 'Не вдалося завершити вхід через Сільпо. Спробуйте авторизуватися ще раз.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        if ($request->session()->pull('share_target.return_after_auth', false)) {
            return redirect()->route('share-target.show')->with('success', 'Ви увійшли через Сільпо. Картинки нікуди не втекли.');
        }

        return redirect()->route('events.index')->with('success', 'Ви увійшли через Сільпо.');
    }
}
