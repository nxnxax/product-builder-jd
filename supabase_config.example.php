<?php
return [
    // For NEW-format publishable keys (sb_publishable_*), leave jwt_secret blank.
    // Verification will fall back to calling Supabase /auth/v1/user with the user's token.
    // For LEGACY anon keys, provide the JWT secret to enable local HS256 verification.
    'jwt_secret' => '',
    'issuer' => 'https://your-project-ref.supabase.co/auth/v1',
    'audience' => 'authenticated',
    'require_auth' => true,

    // REQUIRED for the userinfo fallback (new publishable-key projects).
    // Either set supabase_url here, or rely on the issuer above.
    'supabase_url' => 'https://your-project-ref.supabase.co',
    // Anon/publishable key — required by Supabase /auth/v1 endpoints as `apikey` header.
    'anon_key' => 'your-supabase-anon-key',
];
