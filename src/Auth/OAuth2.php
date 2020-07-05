<?php
/*
 * Copyright 2015 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Auth;

use Firebase\JWT\JWT;
use Google\Auth\Http\ClientFactory;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * OAuth2 supports authentication by OAuth2 2-legged flows.
 *
 * It primary supports
 * - service account authorization
 * - authorization where a user already has an access token
 */
class OAuth2
{
    private const DEFAULT_EXPIRY_SECONDS = 3600; // 1 hour
    private const DEFAULT_SKEW_SECONDS = 60; // 1 minute
    private const JWT_URN = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * TODO: determine known methods from the keys of JWT::methods.
     */
    private static $knownSigningAlgorithms = array(
        'HS256',
        'HS512',
        'HS384',
        'RS256',
    );

    /**
     * The well known grant types.
     *
     * @var array
     */
    private static $knownGrantTypes = array(
        'authorization_code',
        'refresh_token',
        'password',
        'client_credentials',
    );

    /**
     * - authorizationUri
     *   The authorization server's HTTP endpoint capable of
     *   authenticating the end-user and obtaining authorization.
     *
     * @var UriInterface
     */
    private $authorizationUri;

    /**
     * - tokenCredentialUri
     *   The authorization server's HTTP endpoint capable of issuing
     *   tokens and refreshing expired tokens.
     *
     * @var UriInterface
     */
    private $tokenCredentialUri;

    /**
     * The redirection URI used in the initial request.
     *
     * @var string
     */
    private $redirectUri;

    /**
     * A unique identifier issued to the client to identify itself to the
     * authorization server.
     *
     * @var string
     */
    private $clientId;

    /**
     * A shared symmetric secret issued by the authorization server, which is
     * used to authenticate the client.
     *
     * @var string
     */
    private $clientSecret;

    /**
     * The resource owner's username.
     *
     * @var string
     */
    private $username;

    /**
     * The resource owner's password.
     *
     * @var string
     */
    private $password;

    /**
     * The scope of the access request, expressed either as an Array or as a
     * space-delimited string.
     *
     * @var array
     */
    private $scope;

    /**
     * An arbitrary string designed to allow the client to maintain state.
     *
     * @var string
     */
    private $state;

    /**
     * The authorization code issued to this client.
     *
     * Only used by the authorization code access grant type.
     *
     * @var string
     */
    private $code;

    /**
     * The issuer ID when using assertion profile.
     *
     * @var string
     */
    private $issuer;

    /**
     * The target audience for assertions.
     *
     * @var string
     */
    private $audience;

    /**
     * The target sub when issuing assertions.
     *
     * @var string
     */
    private $sub;

    /**
     * The number of seconds assertions are valid for.
     *
     * @var int
     */
    private $expiry;

    /**
     * The signing key when using assertion profile.
     *
     * @var string
     */
    private $signingKey;

    /**
     * The signing key id when using assertion profile. Param kid in jwt header
     *
     * @var string
     */
    private $signingKeyId;

    /**
     * The signing algorithm when using an assertion profile.
     *
     * @var string
     */
    private $signingAlgorithm;

    /**
     * The refresh token associated with the access token to be refreshed.
     *
     * @var string
     */
    private $refreshToken;

    /**
     * The current access token.
     *
     * @var string
     */
    private $accessToken;

    /**
     * The current ID token.
     *
     * @var string
     */
    private $idToken;

    /**
     * The lifetime in seconds of the current access token.
     *
     * @var int
     */
    private $expiresIn;

    /**
     * The expiration time of the access token as a number of seconds since the
     * unix epoch.
     *
     * @var int
     */
    private $expiresAt;

    /**
     * The issue time of the access token as a number of seconds since the unix
     * epoch.
     *
     * @var int
     */
    private $issuedAt;

    /**
     * The current grant type.
     *
     * @var string
     */
    private $grantType;

    /**
     * When using an extension grant type, this is the set of parameters used by
     * that extension.
     */
    private $extensionParams;

    /**
     * When using the toJwt function, these claims will be added to the JWT
     * payload.
     */
    private $additionalClaims;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * Create a new OAuthCredentials.
     *
     * The configuration array accepts various options
     *
     * - authorizationUri
     *   The authorization server's HTTP endpoint capable of
     *   authenticating the end-user and obtaining authorization.
     *
     * - tokenCredentialUri
     *   The authorization server's HTTP endpoint capable of issuing
     *   tokens and refreshing expired tokens.
     *
     * - clientId
     *   A unique identifier issued to the client to identify itself to the
     *   authorization server.
     *
     * - clientSecret
     *   A shared symmetric secret issued by the authorization server,
     *   which is used to authenticate the client.
     *
     * - scope
     *   The scope of the access request, expressed either as an Array
     *   or as a space-delimited String.
     *
     * - state
     *   An arbitrary string designed to allow the client to maintain state.
     *
     * - redirectUri
     *   The redirection URI used in the initial request.
     *
     * - username
     *   The resource owner's username.
     *
     * - password
     *   The resource owner's password.
     *
     * - issuer
     *   Issuer ID when using assertion profile
     *
     * - audience
     *   Target audience for assertions
     *
     * - expiry
     *   Number of seconds assertions are valid for
     *
     * - signingKey
     *   Signing key when using assertion profile
     *
     * - signingKeyId
     *   Signing key id when using assertion profile
     *
     * - extensionParams
     *   When using an extension grant type, this is the set of parameters used
     *   by that extension.
     *
     * @param array $config Configuration array
     */
    public function __construct(array $config)
    {
        $opts = array_merge([
            'httpClient' => null,
            'expiry' => self::DEFAULT_EXPIRY_SECONDS,
            'extensionParams' => [],
            'authorizationUri' => null,
            'redirectUri' => null,
            'tokenCredentialUri' => null,
            'state' => null,
            'username' => null,
            'password' => null,
            'clientId' => null,
            'clientSecret' => null,
            'refreshToken' => null,
            'issuer' => null,
            'sub' => null,
            'audience' => null,
            'signingKey' => null,
            'signingKeyId' => null,
            'signingAlgorithm' => null,
            'scope' => null,
            'additionalClaims' => [],
        ], $config);

        $this->httpClient = $opts['httpClient'] ?: ClientFactory::build();
        $this->setAuthorizationUri($opts['authorizationUri']);
        $this->setRedirectUri($opts['redirectUri']);
        $this->setTokenCredentialUri($opts['tokenCredentialUri']);
        $this->setState($opts['state']);
        $this->setUsername($opts['username']);
        $this->setPassword($opts['password']);
        $this->setClientId($opts['clientId']);
        $this->setClientSecret($opts['clientSecret']);
        $this->setRefreshToken($opts['refreshToken']);
        $this->setIssuer($opts['issuer']);
        $this->setSub($opts['sub']);
        $this->setExpiry($opts['expiry']);
        $this->setAudience($opts['audience']);
        $this->setSigningKey($opts['signingKey']);
        $this->setSigningKeyId($opts['signingKeyId']);
        $this->setSigningAlgorithm($opts['signingAlgorithm']);
        $this->setScope($opts['scope']);
        $this->setExtensionParams($opts['extensionParams']);
        $this->setAdditionalClaims($opts['additionalClaims']);
    }

    /**
     * Verifies the idToken if present.
     *
     * - if none is present, return null
     * - if present, but invalid, raises DomainException.
     * - otherwise returns the payload in the idtoken as a PHP object.
     *
     * The behavior of this method varies depending on the version of
     * `firebase/php-jwt` you are using. In versions lower than 3.0.0, if
     * `$publicKey` is null, the key is decoded without being verified. In
     * newer versions, if a public key is not given, this method will throw an
     * `\InvalidArgumentException`.
     *
     * @param string $publicKey The public key to use to authenticate the token
     * @param array $allowed_algs List of supported verification algorithms
     * @throws \DomainException if the token is missing an audience.
     * @throws \DomainException if the audience does not match the one set in
     *         the OAuth2 class instance.
     * @throws \UnexpectedValueException If the token is invalid
     * @throws SignatureInvalidException If the signature is invalid.
     * @throws BeforeValidException If the token is not yet valid.
     * @throws ExpiredException If the token has expired.
     * @return null|object
     */
    public function verifyIdToken($publicKey = null, $allowed_algs = array())
    {
        $idToken = $this->getIdToken();
        if (is_null($idToken)) {
            return null;
        }

        $resp = JWT::decode($idToken, $publicKey, $allowed_algs);
        if (!property_exists($resp, 'aud')) {
            throw new \DomainException('No audience found the id token');
        }
        if ($resp->aud != $this->getAudience()) {
            throw new \DomainException('Wrong audience present in the id token');
        }

        return $resp;
    }

    /**
     * Obtains the encoded jwt from the instance data.
     *
     * @param array $config array optional configuration parameters
     * @return string
     */
    public function toJwt(array $config = [])
    {
        if (is_null($this->getSigningKey())) {
            throw new \DomainException('No signing key available');
        }
        if (is_null($this->getSigningAlgorithm())) {
            throw new \DomainException('No signing algorithm specified');
        }
        $now = time();

        $opts = array_merge([
            'skew' => self::DEFAULT_SKEW_SECONDS,
        ], $config);

        $assertion = [
            'iss' => $this->getIssuer(),
            'aud' => $this->getAudience(),
            'exp' => ($now + $this->getExpiry()),
            'iat' => ($now - $opts['skew']),
        ];
        foreach ($assertion as $k => $v) {
            if (is_null($v)) {
                throw new \DomainException($k . ' should not be null');
            }
        }
        if (!(is_null($this->getScope()))) {
            $assertion['scope'] = $this->getScope();
        }
        if (!(is_null($this->getSub()))) {
            $assertion['sub'] = $this->getSub();
        }
        $assertion += $this->getAdditionalClaims();

        return JWT::encode(
            $assertion,
            $this->getSigningKey(),
            $this->getSigningAlgorithm(),
            $this->getSigningKeyId()
        );
    }

    /**
     * Fetches the auth tokens based on the current state.
     *
     * @return array the response
     */
    public function fetchAuthToken(): array
    {
        $response = $this->httpClient->send(
            $this->generateCredentialsRequest()
        );
        $credentials = $this->parseTokenResponse($response);
        $this->setAuthToken($credentials);

        return $credentials;
    }

    /**
     * Generates a request for token credentials.
     *
     * @return RequestInterface the authorization Url.
     */
    private function generateCredentialsRequest()
    {
        $uri = $this->getTokenCredentialUri();
        if (is_null($uri)) {
            throw new \DomainException('No token credential URI was set.');
        }

        $grantType = $this->getGrantType();
        $params = array('grant_type' => $grantType);
        switch ($grantType) {
            case 'authorization_code':
                $params['code'] = $this->getCode();
                $params['redirect_uri'] = $this->getRedirectUri();
                $this->addClientCredentials($params);
                break;
            case 'password':
                $params['username'] = $this->getUsername();
                $params['password'] = $this->getPassword();
                $this->addClientCredentials($params);
                break;
            case 'refresh_token':
                $params['refresh_token'] = $this->getRefreshToken();
                $this->addClientCredentials($params);
                break;
            case self::JWT_URN:
                $params['assertion'] = $this->toJwt();
                break;
            default:
                if (!is_null($this->getRedirectUri())) {
                    # Grant type was supposed to be 'authorization_code', as there
                    # is a redirect URI.
                    throw new \DomainException('Missing authorization code');
                }
                unset($params['grant_type']);
                if (!is_null($grantType)) {
                    $params['grant_type'] = $grantType;
                }
                $params = array_merge($params, $this->getExtensionParams());
        }

        $headers = [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        return new Request(
            'POST',
            $uri,
            $headers,
            Psr7\build_query($params)
        );
    }

    /**
     * Parses the fetched tokens.
     *
     * @param ResponseInterface $resp the response.
     * @return array the tokens parsed from the response body.
     * @throws \Exception
     */
    private function parseTokenResponse(ResponseInterface $resp)
    {
        $body = (string)$resp->getBody();
        if ($resp->hasHeader('Content-Type') &&
            $resp->getHeaderLine('Content-Type') == 'application/x-www-form-urlencoded'
        ) {
            $res = array();
            parse_str($body, $res);

            return $res;
        }

        // Assume it's JSON; if it's not throw an exception
        if (null === $res = json_decode($body, true)) {
            throw new \Exception('Invalid JSON response');
        }

        return $res;
    }

    /**
     * Sets properties of the OAuth2 token, usually after loading from cache.
     *
     * Example:
     * ```
     * $oauth->setAuthToken([
     *     'refresh_token' => 'n4E9O119d',
     *     'access_token' => 'FJQbwq9',
     *     'expires_in' => 3600
     * ]);
     * ```
     *
     * @param array $authToken
     *  The configuration parameters related to the token.
     *
     *  - refresh_token
     *    The refresh token associated with the access token
     *    to be refreshed.
     *
     *  - access_token
     *    The current access token for this client.
     *
     *  - id_token
     *    The current ID token for this client.
     *
     *  - expires_in
     *    The time in seconds until access token expiration.
     *
     *  - expires_at
     *    The time as an integer number of seconds since the Epoch
     *
     *  - issued_at
     *    The timestamp that the token was issued at.
     */
    public function setAuthToken(array $authToken)
    {
        $opts = array_merge([
            'extensionParams' => [],
            'access_token' => null,
            'id_token' => null,
            'expires_in' => null,
            'expires_at' => null,
            'issued_at' => null,
        ], $authToken);

        $this->setExpiresAt($opts['expires_at']);
        $this->setExpiresIn($opts['expires_in']);

        // By default, the token is issued at `Time.now` when `expiresIn` is set,
        // but this can be used to supply a more precise time.
        if (!is_null($opts['issued_at'])) {
            $this->setIssuedAt($opts['issued_at']);
        }

        $this->setAccessToken($opts['access_token']);
        $this->setIdToken($opts['id_token']);

        // The refresh token should only be updated if a value is explicitly
        // passed in, as some access token responses do not include a refresh
        // token.
        if (array_key_exists('refresh_token', $opts)) {
            $this->setRefreshToken($opts['refresh_token']);
        }
    }

    /**
     * Verifies an id token and returns the authenticated apiLoginTicket.
     * Throws an exception if the id token is not valid.
     * The audience parameter can be used to control which id tokens are
     * accepted.  By default, the id token must have been issued to this OAuth2 client.
     *
     * @param string $token The JSON Web Token to be verified.
     * @param array $options [optional] Configuration options.
     * @param string $options.audience The indended recipient of the token.
     * @param string $options.issuer The intended issuer of the token.
     * @param string $options.certsLocation The location (remote or local) from which
     *        to retrieve certificates, if not cached. This value should only be
     *        provided in limited circumstances in which you are sure of the
     *        behavior.
     * @param bool $options.throwException Whether the function should throw an
     *        exception if the verification fails. This is useful for
     *        determining the reason verification failed.
     * @return array|bool the token payload, if successful, or false if not.
     * @throws InvalidArgumentException If certs could not be retrieved from a local file.
     * @throws InvalidArgumentException If received certs are in an invalid format.
     * @throws InvalidArgumentException If the cert alg is not supported.
     * @throws RuntimeException If certs could not be retrieved from a remote location.
     * @throws UnexpectedValueException If the token issuer does not match.
     * @throws UnexpectedValueException If the token audience does not match.
     */
    public function verify($token, array $options = [])
    {
        $audience = isset($options['audience'])
            ? $options['audience']
            : null;
        $issuer = isset($options['issuer'])
            ? $options['issuer']
            : null;
        $certsLocation = isset($options['certsLocation'])
            ? $options['certsLocation']
            : self::FEDERATED_SIGNON_CERT_URL;
        $throwException = isset($options['throwException'])
            ? $options['throwException']
            : false; // for backwards compatibility

        // Check signature against each available cert.
        $certs = $this->getCerts($certsLocation, $options);
        $alg = $this->determineAlg($certs);
        if (!in_array($alg, ['RS256', 'ES256'])) {
            throw new InvalidArgumentException(
                'unrecognized "alg" in certs, expected ES256 or RS256'
            );
        }
        try {
            if ($alg == 'RS256') {
                return $this->verifyRs256($token, $certs, $audience, $issuer);
            }
            return $this->verifyEs256($token, $certs, $audience, $issuer);
        } catch (ExpiredException $e) {  // firebase/php-jwt 3+
        } catch (\ExpiredException $e) { // firebase/php-jwt 2
        } catch (SignatureInvalidException $e) {  // firebase/php-jwt 3+
        } catch (\SignatureInvalidException $e) { // firebase/php-jwt 2
        } catch (InvalidTokenException $e) { // simplejwt
        } catch (DomainException $e) {
        } catch (InvalidArgumentException $e) {
        } catch (UnexpectedValueException $e) {
        }

        if ($throwException) {
            throw $e;
        }

        return false;
    }

    /**
     * Verifies an ES256-signed JWT.
     *
     * @param string $token The JSON Web Token to be verified.
     * @param array $certs Certificate array according to the JWK spec (see
     *        https://tools.ietf.org/html/rfc7517).
     * @param string|null $audience If set, returns false if the provided
     *        audience does not match the "aud" claim on the JWT.
     * @param string|null $issuer If set, returns false if the provided
     *        issuer does not match the "iss" claim on the JWT.
     * @return array|bool the token payload, if successful, or false if not.
     */
    private function verifyEs256($token, array $certs, $audience = null, $issuer = null)
    {
        $this->checkSimpleJwt();

        $jwkset = new KeySet();
        foreach ($certs as $cert) {
            $jwkset->add(KeyFactory::create($cert, 'php'));
        }

        // Validate the signature using the key set and ES256 algorithm.
        $jwt = $this->callSimpleJwtDecode([$token, $jwkset, 'ES256']);
        $payload = $jwt->getClaims();

        if (isset($payload['aud'])) {
            if ($audience && $payload['aud'] != $audience) {
                throw new UnexpectedValueException('Audience does not match');
            }
        }

        // @see https://cloud.google.com/iap/docs/signed-headers-howto#verifying_the_jwt_payload
        $issuer = $issuer ?: self::IAP_ISSUER;
        if (!isset($payload['iss']) || $payload['iss'] !== $issuer) {
            throw new UnexpectedValueException('Issuer does not match');
        }

        return $payload;
    }

    /**
     * Verifies an RS256-signed JWT.
     *
     * @param string $token The JSON Web Token to be verified.
     * @param array $certs Certificate array according to the JWK spec (see
     *        https://tools.ietf.org/html/rfc7517).
     * @param string|null $audience If set, returns false if the provided
     *        audience does not match the "aud" claim on the JWT.
     * @param string|null $issuer If set, returns false if the provided
     *        issuer does not match the "iss" claim on the JWT.
     * @return array|bool the token payload, if successful, or false if not.
     */
    private function verifyRs256($token, array $certs, $audience = null, $issuer = null)
    {
        $this->checkAndInitializePhpsec();
        $keys = [];
        foreach ($certs as $cert) {
            if (empty($cert['kid'])) {
                throw new InvalidArgumentException(
                    'certs expects "kid" to be set'
                );
            }
            if (empty($cert['n']) || empty($cert['e'])) {
                throw new InvalidArgumentException(
                    'RSA certs expects "n" and "e" to be set'
                );
            }
            $rsa = new RSA();
            $rsa->loadKey([
                'n' => new BigInteger($this->callJwtStatic('urlsafeB64Decode', [
                    $cert['n'],
                ]), 256),
                'e' => new BigInteger($this->callJwtStatic('urlsafeB64Decode', [
                    $cert['e']
                ]), 256),
            ]);

            // create an array of key IDs to certs for the JWT library
            $keys[$cert['kid']] =  $rsa->getPublicKey();
        }

        $payload = $this->callJwtStatic('decode', [
            $token,
            $keys,
            ['RS256']
        ]);

        if (property_exists($payload, 'aud')) {
            if ($audience && $payload->aud != $audience) {
                throw new UnexpectedValueException('Audience does not match');
            }
        }

        // support HTTP and HTTPS issuers
        // @see https://developers.google.com/identity/sign-in/web/backend-auth
        $issuers = $issuer ? [$issuer] : [self::OAUTH2_ISSUER, self::OAUTH2_ISSUER_HTTPS];
        if (!isset($payload->iss) || !in_array($payload->iss, $issuers)) {
            throw new UnexpectedValueException('Issuer does not match');
        }

        return (array) $payload;
    }

    /**
     * Identifies the expected algorithm to verify by looking at the "alg" key
     * of the provided certs.
     *
     * @param array $certs Certificate array according to the JWK spec (see
     *                     https://tools.ietf.org/html/rfc7517).
     * @return string The expected algorithm, such as "ES256" or "RS256".
     */
    private function determineAlg(array $certs)
    {
        $alg = null;
        foreach ($certs as $cert) {
            if (empty($cert['alg'])) {
                throw new InvalidArgumentException(
                    'certs expects "alg" to be set'
                );
            }
            $alg = $alg ?: $cert['alg'];

            if ($alg != $cert['alg']) {
                throw new InvalidArgumentException(
                    'More than one alg detected in certs'
                );
            }
        }
        return $alg;
    }

    /**
     * Revoke an OAuth2 access token or refresh token. This method will revoke the current access
     * token, if a token isn't provided.
     *
     * @param string|array $token The token (access token or a refresh token) that should be revoked.
     * @param array $options [optional] Configuration options.
     * @return bool Returns True if the revocation was successful, otherwise False.
     */
    public function revoke($token, array $options = [])
    {
        if (is_array($token)) {
            if (isset($token['refresh_token'])) {
                $token = $token['refresh_token'];
            } else {
                $token = $token['access_token'];
            }
        }

        $body = Psr7\stream_for(http_build_query(['token' => $token]));
        $request = new Request('POST', self::OAUTH2_REVOKE_URI, [
            'Cache-Control' => 'no-store',
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ], $body);

        $httpHandler = $this->httpHandler;

        $response = $httpHandler($request, $options);

        return $response->getStatusCode() == 200;
    }

    /**
     * Builds the authorization Uri that the user should be redirected to.
     *
     * @param array $config configuration options that customize the return url
     * @return UriInterface the authorization Url.
     * @throws InvalidArgumentException
     */
    public function buildFullAuthorizationUri(array $config = [])
    {
        if (is_null($this->getAuthorizationUri())) {
            throw new InvalidArgumentException(
                'requires an authorizationUri to have been set'
            );
        }

        $params = array_merge([
            'response_type' => 'code',
            'access_type' => 'offline',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'scope' => $this->getScope(),
        ], $config);

        // Validate the auth_params
        if (is_null($params['client_id'])) {
            throw new InvalidArgumentException(
                'missing the required client identifier'
            );
        }
        if (is_null($params['redirect_uri'])) {
            throw new InvalidArgumentException('missing the required redirect URI');
        }
        if (!empty($params['prompt']) && !empty($params['approval_prompt'])) {
            throw new InvalidArgumentException(
                'prompt and approval_prompt are mutually exclusive'
            );
        }

        // Construct the uri object; return it if it is valid.
        $result = clone $this->authorizationUri;
        $existingParams = Psr7\parse_query($result->getQuery());

        $result = $result->withQuery(
            Psr7\build_query(array_merge($existingParams, $params))
        );

        if ($result->getScheme() != 'https') {
            throw new InvalidArgumentException(
                'Authorization endpoint must be protected by TLS'
            );
        }

        return $result;
    }

    /**
     * Sets the authorization server's HTTP endpoint capable of authenticating
     * the end-user and obtaining authorization.
     *
     * @param string $uri
     */
    public function setAuthorizationUri(?string $uri): void
    {
        $this->authorizationUri = $this->coerceUri($uri);
    }

    /**
     * Gets the authorization server's HTTP endpoint capable of authenticating
     * the end-user and obtaining authorization.
     *
     * @return UriInterface
     */
    public function getAuthorizationUri()
    {
        return $this->authorizationUri;
    }

    /**
     * Gets the authorization server's HTTP endpoint capable of issuing tokens
     * and refreshing expired tokens.
     *
     * @return string
     */
    public function getTokenCredentialUri()
    {
        return $this->tokenCredentialUri;
    }

    /**
     * Sets the authorization server's HTTP endpoint capable of issuing tokens
     * and refreshing expired tokens.
     *
     * @param string $uri
     */
    public function setTokenCredentialUri($uri)
    {
        $this->tokenCredentialUri = $this->coerceUri($uri);
    }

    /**
     * Gets the redirection URI used in the initial request.
     *
     * @return string
     */
    public function getRedirectUri()
    {
        return $this->redirectUri;
    }

    /**
     * Sets the redirection URI used in the initial request.
     *
     * @param string $uri
     */
    public function setRedirectUri($uri)
    {
        if (is_null($uri)) {
            $this->redirectUri = null;

            return;
        }
        // redirect URI must be absolute
        if (!$this->isAbsoluteUri($uri)) {
            // "postmessage" is a reserved URI string in Google-land
            // @see https://developers.google.com/identity/sign-in/web/server-side-flow
            if ('postmessage' !== (string)$uri) {
                throw new InvalidArgumentException(
                    'Redirect URI must be absolute'
                );
            }
        }
        $this->redirectUri = (string)$uri;
    }

    /**
     * Gets the scope of the access requests as a space-delimited String.
     *
     * @return string
     */
    public function getScope()
    {
        if (is_null($this->scope)) {
            return $this->scope;
        }

        return implode(' ', $this->scope);
    }

    /**
     * Sets the scope of the access request, expressed either as an Array or as
     * a space-delimited String.
     *
     * @param string|array $scope
     * @throws InvalidArgumentException
     */
    public function setScope($scope)
    {
        if (is_null($scope)) {
            $this->scope = null;
        } elseif (is_string($scope)) {
            $this->scope = explode(' ', $scope);
        } elseif (is_array($scope)) {
            foreach ($scope as $s) {
                $pos = strpos($s, ' ');
                if ($pos !== false) {
                    throw new InvalidArgumentException(
                        'array scope values should not contain spaces'
                    );
                }
            }
            $this->scope = $scope;
        } else {
            throw new InvalidArgumentException(
                'scopes should be a string or array of strings'
            );
        }
    }

    /**
     * Gets the current grant type.
     *
     * @return string
     */
    public function getGrantType()
    {
        if (!is_null($this->grantType)) {
            return $this->grantType;
        }

        // Returns the inferred grant type, based on the current object instance
        // state.
        if (!is_null($this->code)) {
            return 'authorization_code';
        }

        if (!is_null($this->refreshToken)) {
            return 'refresh_token';
        }

        if (!is_null($this->username) && !is_null($this->password)) {
            return 'password';
        }

        if (!is_null($this->issuer) && !is_null($this->signingKey)) {
            return self::JWT_URN;
        }

        return null;
    }

    /**
     * Sets the current grant type.
     *
     * @param $grantType
     * @throws InvalidArgumentException
     */
    public function setGrantType($grantType)
    {
        if (in_array($grantType, self::$knownGrantTypes)) {
            $this->grantType = $grantType;
        } else {
            // validate URI
            if (!$this->isAbsoluteUri($grantType)) {
                throw new InvalidArgumentException(
                    'invalid grant type'
                );
            }
            $this->grantType = (string)$grantType;
        }
    }

    /**
     * Gets an arbitrary string designed to allow the client to maintain state.
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Sets an arbitrary string designed to allow the client to maintain state.
     *
     * @param string $state
     */
    public function setState($state)
    {
        $this->state = $state;
    }

    /**
     * Gets the authorization code issued to this client.
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Sets the authorization code issued to this client.
     *
     * @param string $code
     */
    public function setCode($code)
    {
        $this->code = $code;
    }

    /**
     * Gets the resource owner's username.
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Sets the resource owner's username.
     *
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * Gets the resource owner's password.
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the resource owner's password.
     *
     * @param $password
     */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    /**
     * Sets a unique identifier issued to the client to identify itself to the
     * authorization server.
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * Sets a unique identifier issued to the client to identify itself to the
     * authorization server.
     *
     * @param $clientId
     */
    public function setClientId($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Gets a shared symmetric secret issued by the authorization server, which
     * is used to authenticate the client.
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * Sets a shared symmetric secret issued by the authorization server, which
     * is used to authenticate the client.
     *
     * @param $clientSecret
     */
    public function setClientSecret($clientSecret)
    {
        $this->clientSecret = $clientSecret;
    }

    /**
     * Gets the Issuer ID when using assertion profile.
     */
    public function getIssuer()
    {
        return $this->issuer;
    }

    /**
     * Sets the Issuer ID when using assertion profile.
     *
     * @param string $issuer
     */
    public function setIssuer($issuer)
    {
        $this->issuer = $issuer;
    }

    /**
     * Gets the target sub when issuing assertions.
     */
    public function getSub()
    {
        return $this->sub;
    }

    /**
     * Sets the target sub when issuing assertions.
     *
     * @param string $sub
     */
    public function setSub($sub)
    {
        $this->sub = $sub;
    }

    /**
     * Gets the target audience when issuing assertions.
     */
    public function getAudience()
    {
        return $this->audience;
    }

    /**
     * Sets the target audience when issuing assertions.
     *
     * @param string $audience
     */
    public function setAudience($audience)
    {
        $this->audience = $audience;
    }

    /**
     * Gets the signing key when using an assertion profile.
     */
    public function getSigningKey()
    {
        return $this->signingKey;
    }

    /**
     * Sets the signing key when using an assertion profile.
     *
     * @param string $signingKey
     */
    public function setSigningKey($signingKey)
    {
        $this->signingKey = $signingKey;
    }

    /**
     * Gets the signing key id when using an assertion profile.
     *
     * @return string
     */
    public function getSigningKeyId()
    {
        return $this->signingKeyId;
    }

    /**
     * Sets the signing key id when using an assertion profile.
     *
     * @param string $signingKeyId
     */
    public function setSigningKeyId($signingKeyId)
    {
        $this->signingKeyId = $signingKeyId;
    }

    /**
     * Gets the signing algorithm when using an assertion profile.
     *
     * @return string
     */
    public function getSigningAlgorithm()
    {
        return $this->signingAlgorithm;
    }

    /**
     * Sets the signing algorithm when using an assertion profile.
     *
     * @param string $signingAlgorithm
     */
    public function setSigningAlgorithm($signingAlgorithm)
    {
        if (is_null($signingAlgorithm)) {
            $this->signingAlgorithm = null;
        } elseif (!in_array($signingAlgorithm, self::$knownSigningAlgorithms)) {
            throw new InvalidArgumentException('unknown signing algorithm');
        } else {
            $this->signingAlgorithm = $signingAlgorithm;
        }
    }

    /**
     * Gets the set of parameters used by extension when using an extension
     * grant type.
     */
    public function getExtensionParams(): array
    {
        return $this->extensionParams;
    }

    /**
     * Sets the set of parameters used by extension when using an extension
     * grant type.
     *
     * @param $extensionParams
     */
    public function setExtensionParams(array $extensionParams)
    {
        $this->extensionParams = $extensionParams;
    }

    /**
     * Gets the number of seconds assertions are valid for.
     */
    public function getExpiry(): ?int
    {
        return $this->expiry;
    }

    /**
     * Sets the number of seconds assertions are valid for.
     *
     * @param int $expiry
     */
    public function setExpiry(int $expiry): void
    {
        $this->expiry = $expiry;
    }

    /**
     * Gets the lifetime of the access token in seconds.
     */
    public function getExpiresIn(): ?int
    {
        return $this->expiresIn;
    }

    /**
     * Sets the lifetime of the access token in seconds.
     *
     * @param int $expiresIn
     */
    public function setExpiresIn(?int $expiresIn): void
    {
        if (is_null($expiresIn)) {
            $this->expiresIn = null;
            $this->issuedAt = null;
        } else {
            $this->issuedAt = time();
            $this->expiresIn = (int)$expiresIn;
        }
    }

    /**
     * Gets the time the current access token expires at.
     *
     * @return int|null
     */
    public function getExpiresAt(): ?int
    {
        if (!is_null($this->expiresAt)) {
            return $this->expiresAt;
        }

        if (!is_null($this->issuedAt) && !is_null($this->expiresIn)) {
            return $this->issuedAt + $this->expiresIn;
        }

        return null;
    }

    /**
     * Returns true if the acccess token has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        $expiration = $this->getExpiresAt();
        $now = time();

        return is_null($expiration) || $now >= $expiration;
    }

    /**
     * Sets the time the current access token expires at.
     *
     * @param int $expiresAt
     */
    public function setExpiresAt(?int $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /**
     * Gets the time the current access token was issued at.
     *
     * @return int|null
     */
    public function getIssuedAt(): ?int
    {
        return $this->issuedAt;
    }

    /**
     * Sets the time the current access token was issued at.
     *
     * @param int $issuedAt
     */
    public function setIssuedAt(int $issuedAt): void
    {
        $this->issuedAt = $issuedAt;
    }

    /**
     * Gets the current access token.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Sets the current access token.
     *
     * @param string|null $accessToken
     */
    public function setAccessToken(string $accessToken = null): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Gets the current ID token.
     *
     * @return string|null
     */
    public function getIdToken(): ?string
    {
        return $this->idToken;
    }

    /**
     * Sets the current ID token.
     *
     * @param string|null $idToken
     */
    public function setIdToken(string $idToken = null): void
    {
        $this->idToken = $idToken;
    }

    /**
     * Gets the refresh token associated with the current access token.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * Sets the refresh token associated with the current access token.
     *
     * @param string|null $refreshToken
     */
    public function setRefreshToken(?string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    /**
     * Gets the additional claims to be included in the JWT token.
     *
     * @return array
     */
    public function getAdditionalClaims(): array
    {
        return $this->additionalClaims;
    }

    /**
     * Sets additional claims to be included in the JWT token
     *
     * @param array $additionalClaims
     */
    public function setAdditionalClaims(array $additionalClaims): void
    {
        $this->additionalClaims = $additionalClaims;
    }

    /**
     * The expiration of the last received token.
     *
     * @return array
     */
    public function getLastReceivedToken()
    {
        if ($token = $this->getAccessToken()) {
            return [
                'access_token' => $token,
                'expires_at' => $this->getExpiresAt(),
            ];
        }

        return null;
    }

    /**
     * @param string $uri
     * @return null|UriInterface
     */
    private function coerceUri(?string $uri): ?UriInterface
    {
        if (is_null($uri)) {
            return null;
        }

        return Psr7\uri_for($uri);
    }

    /**
     * Determines if the URI is absolute based on its scheme and host or path
     * (RFC 3986).
     *
     * @param string $uri
     * @return bool
     */
    private function isAbsoluteUri(string $uri): bool
    {
        $uri = $this->coerceUri($uri);

        return $uri->getScheme() && ($uri->getHost() || $uri->getPath());
    }

    /**
     * @param array $params
     */
    private function addClientCredentials(array &$params): void
    {
        $clientId = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        if ($clientId && $clientSecret) {
            $params['client_id'] = $clientId;
            $params['client_secret'] = $clientSecret;
        }
    }
}