export { default } from "next-auth/middleware";

export const config = {
  matcher: ["/dashboard/:path*", "/doctor/:path*", "/admin/:path*"],
};
