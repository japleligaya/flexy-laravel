# Temporary PayrollGO runtime binding check

This review branch starts at the confirmed production commit `233d980`. The diagnostic is disabled unless the Vercel production runtime has an explicit expiration within the next 30 minutes. It accepts the existing PayrollGO integration bearer token and requires the current `super_admin` role. The limit is three requests per administrator per minute using the shared database cache. Only rate-limit counters use that cache. The diagnostic queries PostgreSQL connection metadata on the primary connection.

## After deployment approval

1. Confirm the live alias still points to the known baseline and record its immutable deployment URL in the Vercel dashboard. Use the reviewed diagnostic commit and link its clean worktree to the **existing** PayrollGO Vercel project. In the dashboard enable Vercel system environment variables for Production if necessary and set the Production-only `PAYROLLGO_RUNTIME_BINDING_EXPIRES_AT` to the Unix UTC seconds printed by this command. Set it just before deployment.

   ```powershell
   [DateTimeOffset]::UtcNow.AddMinutes(25).ToUnixTimeSeconds()
   ```

2. From the diagnostic worktree, with the existing project selected by an authorized Vercel operator, run the following. Confirm the immutable deployment returned by Vercel is serving `flexy-laravel.vercel.app`. If the deployment or verification misses the window, issue a new approval window through the same configuration and deployment steps.

   ```powershell
   vercel link --project flexy-laravel
   vercel deploy --prod
   ```

3. Load a current authorized PayrollGO administrator bearer token into `PAYROLLGO_ADMIN_ACCESS_TOKEN` in your process using the approved secret workflow. Use the product's existing integration sign-in flow for a fresh token if needed. Avoid shell tracing or verbose HTTP logs. Query the production alias and clear the process variable immediately afterward.

   ```powershell
   if (!$env:PAYROLLGO_ADMIN_ACCESS_TOKEN) { throw 'Load the approved bearer token first.' }
   try {
       $binding = Invoke-RestMethod -Uri 'https://flexy-laravel.vercel.app/api/v1/integrations/agent/diagnostics/runtime-binding' -Headers @{
           Authorization = 'Bearer ' + $env:PAYROLLGO_ADMIN_ACCESS_TOKEN
       } -MaximumRedirection 0
       $binding | ConvertTo-Json
   } finally {
       Remove-Item Env:PAYROLLGO_ADMIN_ACCESS_TOKEN
   }
   ```

4. Compare `runtime_deployment` to the immutable deployment serving the alias. Independently obtain the authorized Neon staging hostname of the same endpoint type (pooled or direct) and hash the lowercase hostname alone. Compare `runtime_host_sha256` and confirm `database` is `neondb`, `schema_name` is `public`, and `api_version` is `v1`. Keep the five-field response and alias mapping in protected operator evidence. A mismatch blocks approval.

   ```powershell
   $hostNameOnly = (Read-Host 'Neon staging hostname only').Trim().ToLowerInvariant()
   $sha = [Security.Cryptography.SHA256]::Create()
   try {
       $expected = [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($hostNameOnly))).Replace('-', '').ToLowerInvariant()
       if ($binding.runtime_host_sha256 -cne $expected -or $binding.database -cne 'neondb' -or
           $binding.schema_name -cne 'public' -or $binding.api_version -cne 'v1') {
           throw 'Runtime binding mismatch.'
       }
   } finally {
       $sha.Dispose()
   }
   ```

## Remove the diagnostic

After verification, delete the Production expiration variable and restore the recorded prior deployment through Vercel **Instant Rollback**, after confirming no intervening production release. If production changed in the meantime, remove the diagnostic by reverting its commit on the current approved release and deploying that revision. Confirm an authenticated request to the alias returns 404. The diagnostic deployment's immutable URL remains gated by the baked-in expiry even after an alias rollback. Wait for that expiry, then confirm it returns 404 or remove the temporary deployment under the project's normal retention policy. Treat the captured evidence as specific to the deployment that produced it. The alias rollback restores the prior release and does not itself attest to that release's database binding.
