"use client";

import { useEffect, useState } from "react";

import { apiGet } from "@food-delivery/session";
import "maplibre-gl/dist/maplibre-gl.css";
import { MapView } from "@food-delivery/ui/map";

type Envelope<T> = { data: T };

/** The style URL comes from public config at run time (ADR-007), never from a bundled default. */
function useMapStyleUrl(): string | null {
  const [styleUrl, setStyleUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiGet<Envelope<{ map_style_url: string | null }>>("/api/v1/public/config").then((result) => {
      if (!cancelled && result.ok) setStyleUrl(result.value.data.map_style_url);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  return styleUrl;
}

function parse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/** Map beside a GeoJSON field. The text stays the source of truth; invalid text simply draws no shape. */
export function ZoneMap({ shapeText }: { shapeText: string }) {
  const styleUrl = useMapStyleUrl();
  return <MapView label="Zone map" styleUrl={styleUrl} geometry={parse(shapeText)} />;
}
