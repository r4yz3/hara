"use client";

import { useEffect, useState } from "react";
import type { operations } from "@food-delivery/api-client";
import { apiGet, type ApiResult } from "@food-delivery/session";
import { LoadProblem, Panel, quietButton } from "@/components/ui";

type StatusResponse = operations["adminSystemStatus"]["responses"][200]["content"]["application/json"];

const features = [
  ["public_registration", "Public registration"],
  ["live_dispatch", "Live dispatch"],
  ["online_payments", "Online payments"],
  ["rider_tracking", "Rider tracking"],
] as const;

export function SystemStatus() {
  const [request, setRequest] = useState(0);
  const [result, setResult] = useState<ApiResult<StatusResponse> | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiGet<StatusResponse>("/api/v1/admin/system/status").then((response) => {
      if (!cancelled) setResult(response);
    });
    return () => { cancelled = true; };
  }, [request]);

  function refresh() {
    setResult(null);
    setRequest((previous) => previous + 1);
  }

  if (!result) return <p role="status">Loading system status…</p>;
  if (!result.ok) return <LoadProblem failure={result.failure} returnTo="/settings" onRetry={refresh} />;

  const status = result.value.data;

  return (
    <div className="grid max-w-4xl gap-4 md:grid-cols-2">
      <div className="flex flex-wrap items-center justify-between gap-3 md:col-span-2">
        <p className="text-sm text-text-secondary">Read-only status and configuration for this deployment.</p>
        <button type="button" onClick={refresh} className={quietButton}>Refresh status</button>
      </div>
      <Panel>
        <h2 className="text-lg font-semibold">Service checks</h2>
        <dl className="grid gap-2">
          <StatusLine label="Database" value={status.checks.database ? "Available" : "Unavailable"} />
          <StatusLine label="Redis" value={status.checks.redis ? "Available" : "Unavailable"} />
          <StatusLine label="PostGIS" value={status.postgis ? "Available" : "Unavailable"} />
        </dl>
      </Panel>
      <Panel>
        <h2 className="text-lg font-semibold">Deployment</h2>
        <dl className="grid gap-2">
          <StatusLine label="Environment" value={status.environment} />
          <StatusLine label="Debug" value={status.debug ? "Enabled" : "Disabled"} />
          <StatusLine label="Map style" value={status.map_style_configured ? "Configured" : "Pending"} />
          <StatusLine label="Geometry caps" value={status.geometry_caps_approved ? "Approved" : "Pending approval"} />
        </dl>
      </Panel>
      <Panel>
        <h2 className="text-lg font-semibold">Feature flags</h2>
        <dl className="grid gap-2">
          {features.map(([key, label]) => (
            <StatusLine key={key} label={label} value={status.features[key] ? "Enabled in configuration" : "Disabled"} />
          ))}
        </dl>
      </Panel>
      <Panel>
        <h2 className="text-lg font-semibold">Coverage records</h2>
        <dl className="grid gap-2">
          <StatusLine label="Active cities" value={status.cities.active} />
          <StatusLine label="Active zones" value={status.zones.active} />
        </dl>
      </Panel>
    </div>
  );
}

function StatusLine({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm">
      <dt className="text-text-secondary">{label}</dt>
      <dd className="break-all font-medium">{value}</dd>
    </div>
  );
}
