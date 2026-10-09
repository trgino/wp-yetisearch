import { execSync } from 'child_process';

// Runs once before the whole suite: the tests environment starts with plain
// permalinks, which makes /wp-json/ 404. Enable pretty permalinks so the
// REST endpoint and post URLs behave like a real site.
export default function globalSetup(): void {
  execSync('npx wp-env run tests-cli -- wp rewrite structure "/%postname%/" --hard', {
    stdio: 'inherit',
    timeout: 120_000,
  });
}
