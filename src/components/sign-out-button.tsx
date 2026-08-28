"use client";

import { signOut } from "next-auth/react";

export function SignOutButton() {
  return (
    <button
      type="button"
      onClick={() => signOut({ callbackUrl: "/" })}
      className="btn btn-outline !py-2 !text-sm"
    >
      خروج
    </button>
  );
}
