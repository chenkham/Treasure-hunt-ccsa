import com.google.zxing.*;
import com.google.zxing.common.HybridBinarizer;
import com.google.zxing.qrcode.QRCodeReader;
import javax.imageio.ImageIO;
import java.io.File;
import java.nio.charset.StandardCharsets;
import java.util.Map;

/** Uses the same ZXing version as the Android scanner for offline print QA. */
public class QrRead {
 public static void main(String[] args) throws Exception {
  var image=ImageIO.read(new File(args[0]));int w=image.getWidth(),h=image.getHeight();
  var pixels=image.getRGB(0,0,w,h,null,0,w);
  var result=new QRCodeReader().decode(new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(w,h,pixels))),Map.of(DecodeHintType.TRY_HARDER,true));
  System.out.write(result.getText().getBytes(StandardCharsets.UTF_8));
 }
}
