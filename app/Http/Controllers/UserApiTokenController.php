<?php namespace BookStack\Http\Controllers;

use BookStack\Api\ApiToken;
use BookStack\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserApiTokenController extends Controller
{

    /**
     * Show the form to create a new API token.
     */
    public function create(int $userId)
    {
        // Ensure user is has access-api permission and is the current user or has permission to manage the current user.
        $this->checkPermission('access-api');
        $this->checkPermissionOrCurrentUser('manage-users', $userId);

        $user = User::query()->findOrFail($userId);
        return view('users.api-tokens.create', [
            'user' => $user,
        ]);
    }

    /**
     * Store a new API token in the system.
     */
    public function store(Request $request, int $userId)
    {
        $this->checkPermission('access-api');
        $this->checkPermissionOrCurrentUser('manage-users', $userId);

        $this->validate($request, [
            'name' => 'required|max:250',
            'expires_at' => 'date_format:Y-m-d',
        ]);

        $user = User::query()->findOrFail($userId);
        $secret = Str::random(32);
        $expiry = $request->get('expires_at', (Carbon::now()->addYears(100))->format('Y-m-d'));

        $token = (new ApiToken())->forceFill([
            'name' => $request->get('name'),
            'client_id' => Str::random(32),
            'client_secret' => Hash::make($secret),
            'user_id' => $user->id,
            'expires_at' => $expiry
        ]);

        while (ApiToken::query()->where('client_id', '=', $token->client_id)->exists()) {
            $token->client_id = Str::random(32);
        }

        $token->save();
        // TODO - Notification and activity?
        session()->flash('api-token-secret:' . $token->id, $secret);
        return redirect($user->getEditUrl('/api-tokens/' . $token->id));
    }

    /**
     * Show the details for a user API token, with access to edit.
     */
    public function edit(int $userId, int $tokenId)
    {
        [$user, $token] = $this->checkPermissionAndFetchUserToken($userId, $tokenId);
        $secret = session()->pull('api-token-secret:' . $token->id, null);

        return view('users.api-tokens.edit', [
            'user' => $user,
            'token' => $token,
            'model' => $token,
            'secret' => $secret,
        ]);
    }

    /**
     * Update the API token.
     */
    public function update(Request $request, int $userId, int $tokenId)
    {
        $this->validate($request, [
            'name' => 'required|max:250',
            'expires_at' => 'date_format:Y-m-d',
        ]);

        [$user, $token] = $this->checkPermissionAndFetchUserToken($userId, $tokenId);

        $token->fill($request->all())->save();
        // TODO - Notification and activity?
        return redirect($user->getEditUrl('/api-tokens/' . $token->id));
    }

    /**
     * Show the delete view for this token.
     */
    public function delete(int $userId, int $tokenId)
    {
        [$user, $token] = $this->checkPermissionAndFetchUserToken($userId, $tokenId);
        return view('users.api-tokens.delete', [
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Destroy a token from the system.
     */
    public function destroy(int $userId, int $tokenId)
    {
        [$user, $token] = $this->checkPermissionAndFetchUserToken($userId, $tokenId);
        $token->delete();

        // TODO - Notification and activity?, Might have text in translations already (user_api_token_delete_success)
        return redirect($user->getEditUrl('#api_tokens'));
    }

    /**
     * Check the permission for the current user and return an array
     * where the first item is the user in context and the second item is their
     * API token in context.
     */
    protected function checkPermissionAndFetchUserToken(int $userId, int $tokenId): array
    {
        $this->checkPermission('access-api');
        $this->checkPermissionOrCurrentUser('manage-users', $userId);

        $user = User::query()->findOrFail($userId);
        $token = ApiToken::query()->where('user_id', '=', $user->id)->where('id', '=', $tokenId)->firstOrFail();
        return [$user, $token];
    }

}