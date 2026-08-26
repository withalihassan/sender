<?php
require_once __DIR__ . '/../../../aws/aws-autoloader.php';

use Aws\Account\AccountClient;
use Aws\Exception\AwsException;
use Aws\Organizations\OrganizationsClient;
use Aws\Sts\StsClient;

function aws_credentials($account)
{
    return [
        'key' => $account['aws_key'],
        'secret' => $account['aws_secret'],
    ];
}

function get_aws_account_id($awsKey, $awsSecret)
{
    $sts = new StsClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => [
            'key' => $awsKey,
            'secret' => $awsSecret,
        ],
    ]);

    return $sts->getCallerIdentity()->get('Account');
}

function find_org_account_by_email($account, $email)
{
    $client = new OrganizationsClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => aws_credentials($account),
    ]);

    $nextToken = null;

    do {
        $args = $nextToken ? ['NextToken' => $nextToken] : [];
        $result = $client->listAccounts($args);

        foreach ($result['Accounts'] as $orgAccount) {
            if (strtolower($orgAccount['Email'] ?? '') === strtolower($email)) {
                return $orgAccount;
            }
        }

        $nextToken = $result['NextToken'] ?? null;
    } while ($nextToken);

    return null;
}

function update_account_phone($account, $targetAccountId, $phone)
{
    $client = new AccountClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => aws_credentials($account),
    ]);

    $result = $client->getContactInformation([
        'AccountId' => $targetAccountId,
    ]);

    $contact = $result['ContactInformation'];
    $contact['PhoneNumber'] = $phone;

    $client->putContactInformation([
        'AccountId' => $targetAccountId,
        'ContactInformation' => $contact,
    ]);
}

function aws_error_message($e)
{
    if ($e instanceof AwsException) {
        return $e->getAwsErrorMessage() ?: $e->getMessage();
    }

    return $e->getMessage();
}
?>
