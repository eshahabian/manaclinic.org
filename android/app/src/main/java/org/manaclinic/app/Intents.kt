package org.manaclinic.app

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast

fun Context.dialNumber(number: String) {
    val intent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number"))
    try {
        startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(this, "امکان برقراری تماس وجود ندارد", Toast.LENGTH_SHORT).show()
    }
}

fun Context.openUrl(url: String) {
    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
    try {
        startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(this, "برنامه‌ای برای باز کردن لینک پیدا نشد", Toast.LENGTH_SHORT).show()
    }
}

fun Context.sendEmail(email: String) {
    val intent = Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$email"))
    try {
        startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(this, "برنامه ایمیل پیدا نشد", Toast.LENGTH_SHORT).show()
    }
}
