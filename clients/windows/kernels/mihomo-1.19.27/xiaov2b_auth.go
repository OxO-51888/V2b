package main

import (
	"crypto/ed25519"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"
)

const xiaoV2BPublicKeyPEM = `-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAbicAe0hvZe/EH5VKh1cVUqm0oaehwQKf38nH6NsbmlA=
-----END PUBLIC KEY-----`

const (
	xiaoV2BExpectedProduct    = "XiaoV2B"
	xiaoV2BExpectedClientID   = "windows-auth"
)

var xiaoV2BExpectedClientName = "\u4fbf\u5b9c\u673a\u573a"

type xiaoV2BPolicy struct {
	AllowRun  bool `json:"allowRun"`
	AllowCore bool `json:"allowCore"`
	AllowTray bool `json:"allowTray"`
}

type xiaoV2BLicense struct {
	Issuer          string         `json:"issuer"`
	Product         string         `json:"product"`
	ClientID        string         `json:"clientId"`
	ClientName      string         `json:"clientName"`
	RuntimeName     string         `json:"runtimeName"`
	Version         string         `json:"version"`
	DeviceID        string         `json:"deviceId"`
	DeviceName      string         `json:"deviceName"`
	PanelID         string         `json:"panelId"`
	IssuedAt        string         `json:"issuedAt"`
	ExpiresAt       string         `json:"expiresAt"`
	DeviceExpiresAt string         `json:"deviceExpiresAt"`
	Target          string         `json:"target"`
	Policy          xiaoV2BPolicy `json:"policy"`
}

func decodeBase64URL(value string) ([]byte, error) {
	raw := strings.TrimSpace(value)
	if raw == "" {
		return nil, errors.New("empty value")
	}
	if out, err := base64.RawURLEncoding.DecodeString(raw); err == nil {
		return out, nil
	}
	if out, err := base64.URLEncoding.DecodeString(raw); err == nil {
		return out, nil
	}
	if out, err := base64.RawStdEncoding.DecodeString(raw); err == nil {
		return out, nil
	}
	return base64.StdEncoding.DecodeString(raw)
}

func verifyXiaoV2BAuthorization() error {
	payloadValue := strings.TrimSpace(os.Getenv("XIAOV2B_CORE_LICENSE_PAYLOAD"))
	signatureValue := strings.TrimSpace(os.Getenv("XIAOV2B_CORE_LICENSE_SIGNATURE"))
	if payloadValue == "" || signatureValue == "" {
		return errors.New("missing license payload or signature")
	}

	payload, err := base64.StdEncoding.DecodeString(payloadValue)
	if err != nil {
		return fmt.Errorf("invalid license payload: %w", err)
	}
	signature, err := decodeBase64URL(signatureValue)
	if err != nil {
		return fmt.Errorf("invalid license signature: %w", err)
	}

	block, _ := pem.Decode([]byte(xiaoV2BPublicKeyPEM))
	if block == nil {
		return errors.New("invalid embedded public key")
	}
	key, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return fmt.Errorf("invalid public key: %w", err)
	}
	publicKey, ok := key.(ed25519.PublicKey)
	if !ok {
		return errors.New("unsupported public key")
	}
	if !ed25519.Verify(publicKey, payload, signature) {
		return errors.New("signature rejected")
	}

	var license xiaoV2BLicense
	if err := json.Unmarshal(payload, &license); err != nil {
		return fmt.Errorf("invalid license json: %w", err)
	}
	now := time.Now().UTC()
	if license.Product != xiaoV2BExpectedProduct {
		return errors.New("product mismatch")
	}
	if license.ClientID != xiaoV2BExpectedClientID {
		return errors.New("client id mismatch")
	}
	if strings.TrimSpace(license.ClientName) != xiaoV2BExpectedClientName {
		return errors.New("client name mismatch")
	}
	if strings.TrimSpace(license.DeviceID) == "" {
		return errors.New("device id missing")
	}
	expiresAt, err := time.Parse(time.RFC3339, license.ExpiresAt)
	if err != nil {
		return errors.New("license expiry invalid")
	}
	if !expiresAt.After(now.Add(15 * time.Second)) {
		return errors.New("license expired")
	}
	if strings.TrimSpace(license.DeviceExpiresAt) != "" {
		deviceExpiresAt, err := time.Parse(time.RFC3339, license.DeviceExpiresAt)
		if err != nil {
			return errors.New("device expiry invalid")
		}
		if !deviceExpiresAt.After(now) {
			return errors.New("device license expired")
		}
	}
	if !license.Policy.AllowRun || !license.Policy.AllowCore {
		return errors.New("core is not allowed by policy")
	}

	return nil
}
